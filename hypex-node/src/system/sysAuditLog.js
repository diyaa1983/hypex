'use strict';

const db = require('../db');
const companyDecimals = require('../lib/companyDecimals');

const ACTION_LABELS = {
  create: 'إضافة',
  update: 'تعديل',
  save: 'حفظ',
  delete: 'حذف',
  post: 'ترحيل',
  unpost: 'فك ترحيل',
  cancel: 'إلغاء',
  approve: 'اعتماد',
  unapprove: 'فك اعتماد',
  toggle: 'تفعيل/إيقاف',
};

let schemaReady = false;

async function ensureSchema() {
  if (schemaReady) return true;
  try {
    await db.query('SELECT 1 FROM sys_audit_log LIMIT 1');
    schemaReady = true;
    try {
      await db.query('ALTER TABLE sys_audit_log MODIFY COLUMN summary TEXT NULL');
    } catch {
      /* ignore */
    }
    try {
      await db.query('ALTER TABLE sys_audit_log MODIFY COLUMN entity_ref VARCHAR(120) NULL');
    } catch {
      /* ignore */
    }
    return true;
  } catch {
    return false;
  }
}

function buildSummary(parts) {
  const chunks = [];
  for (const [label, value] of Object.entries(parts || {})) {
    const v = String(value == null ? '' : value).trim();
    if (!v || v === '—') continue;
    chunks.push(`${String(label).trim()}: ${v}`);
  }
  return chunks.length ? chunks.join(' | ') : null;
}

function normAuditVal(v) {
  if (v === null || v === undefined) return '';
  if (typeof v === 'boolean') return v ? '1' : '0';
  if (typeof v === 'number' && !Number.isNaN(v)) return String(v);
  const s = String(v).trim();
  if (s === '') return '';
  const n = Number(s.replace(',', '.'));
  if (!Number.isNaN(n) && /^-?\d+([.,]\d+)?$/.test(s.replace(/\s/g, ''))) return String(n);
  return s;
}

function buildOldNewSummary(fieldMap, before, after, identityKeys, displayValue = null) {
  const disp = (key, raw) => {
    if (displayValue) {
      const d = displayValue(key, raw);
      return d == null ? '' : String(d).trim();
    }
    return raw == null ? '' : String(raw).trim();
  };
  const b = before || {};
  const a = after || {};
  const parts = {};
  for (const key of identityKeys || []) {
    const label = fieldMap[key];
    if (!label) continue;
    const v = disp(key, a[key]);
    if (v) parts[label] = v;
  }
  const oldChunks = [];
  const newChunks = [];
  for (const [key, label] of Object.entries(fieldMap || {})) {
    if (normAuditVal(b[key]) === normAuditVal(a[key])) continue;
    const ov = disp(key, b[key]);
    const nv = disp(key, a[key]);
    if (!ov && !nv) continue;
    oldChunks.push(`${label}=${ov || '—'}`);
    newChunks.push(`${label}=${nv || '—'}`);
  }
  if (oldChunks.length) parts['القديم'] = oldChunks.join('، ');
  if (newChunks.length) parts['الجديد'] = newChunks.join('، ');
  return buildSummary(parts);
}

async function formatMoney(amount) {
  try {
    await companyDecimals.load();
    return companyDecimals.formatDisplay(Number(amount) || 0, companyDecimals.amountPlaces());
  } catch {
    return String(Number(amount) || 0);
  }
}

function partyLabel(name, code) {
  const n = String(name || '').trim();
  const c = String(code || '').trim();
  if (!n && !c) return '—';
  if (n && c) return `${n} (${c})`;
  return n || c;
}

function paymentTypeLabel(pt) {
  const p = String(pt || '').toLowerCase();
  if (p === 'credit') return 'ذمم';
  if (p === 'cash') return 'نقدي';
  return p || '—';
}

function orderStatusLabel(status) {
  return String(status || '') === 'approved' ? 'معتمد' : 'مسودة';
}

async function screenMeta(screenCode) {
  try {
    const rows = await db.query('SELECT name_ar FROM sys_screen WHERE code = ? LIMIT 1', [screenCode]);
    if (rows[0]?.name_ar) return { label: String(rows[0].name_ar) };
  } catch {
    /* ignore */
  }
  return { label: screenCode };
}

async function write({
  screenCode,
  actionCode,
  userId = null,
  entityType = '',
  entityId = null,
  entityRef = null,
  docDate = null,
  summary = null,
  screenLabelAr = null,
  domainCode = null,
  actionLabelAr = null,
} = {}) {
  const ok = await ensureSchema();
  if (!ok) return false;
  let actionLabel = String(actionLabelAr || '').trim();
  if (!actionLabel) actionLabel = ACTION_LABELS[actionCode] || '';
  if (!actionLabel) return false;

  const screen = await screenMeta(screenCode);
  const uid = Number(userId) > 0 ? Number(userId) : null;
  const eid = Number(entityId) > 0 ? Number(entityId) : null;
  try {
    await db.query(
      `INSERT INTO sys_audit_log
        (user_id, domain_code, screen_code, screen_label_ar, action_code, action_label_ar,
         entity_type, entity_id, entity_ref, doc_date, summary)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)`,
      [
        uid,
        domainCode || '',
        screenCode,
        screenLabelAr || screen.label || screenCode,
        actionCode,
        actionLabel,
        entityType || '',
        eid,
        entityRef ? String(entityRef) : null,
        docDate ? String(docDate).slice(0, 10) : null,
        summary ? String(summary) : null,
      ]
    );
    return true;
  } catch (e) {
    console.error('sysAuditLog.write', e.message);
    return false;
  }
}

async function fetchCustomerOrderSnapshot(orderId) {
  const id = Number(orderId) || 0;
  if (!id) return null;
  try {
    const rows = await db.query(
      `SELECT o.id, o.order_no, o.order_date, o.total, o.status, o.payment_type,
              c.name_ar AS customer_name, c.code AS customer_code,
              COALESCE(r.name_ar, '') AS sales_rep_name
       FROM sal_customer_order o
       INNER JOIN crm_customer c ON c.id = o.customer_id
       LEFT JOIN crm_sales_rep r ON r.id = o.sales_rep_id
       WHERE o.id = ? LIMIT 1`,
      [id]
    );
    return rows[0] || null;
  } catch {
    return null;
  }
}

async function logCustomerOrder(actionCode, orderId, userId, screenCode = 'sales_customer_order_entry') {
  const row = await fetchCustomerOrderSnapshot(orderId);
  if (!row) return false;
  const money = await formatMoney(row.total);
  const summary = buildSummary({
    العميل: partyLabel(row.customer_name, row.customer_code),
    المندوب: row.sales_rep_name || '',
    المبلغ: money,
    الحالة: orderStatusLabel(row.status),
    الدفع: paymentTypeLabel(row.payment_type),
  });
  return write({
    screenCode,
    actionCode,
    userId,
    entityType: 'sal_customer_order',
    entityId: Number(row.id) || orderId,
    entityRef: row.order_no,
    docDate: row.order_date,
    summary,
  });
}

async function logCustomerOrderSnapshot(actionCode, snapshot, userId, screenCode = 'sales_customer_order_entry') {
  if (!snapshot) return false;
  const money = await formatMoney(snapshot.total);
  const summary = buildSummary({
    العميل: partyLabel(snapshot.customer_name, snapshot.customer_code),
    المندوب: snapshot.sales_rep_name || '',
    المبلغ: money,
    الحالة: orderStatusLabel(snapshot.status),
    الدفع: paymentTypeLabel(snapshot.payment_type),
  });
  return write({
    screenCode,
    actionCode,
    userId,
    entityType: 'sal_customer_order',
    entityId: Number(snapshot.id) > 0 ? Number(snapshot.id) : null,
    entityRef: snapshot.order_no,
    docDate: snapshot.order_date,
    summary,
  });
}

async function fetchVisitLineSnapshot(lineId) {
  const id = Number(lineId) || 0;
  if (!id) return null;
  try {
    const rows = await db.query(
      `SELECT l.id AS line_id, l.visit_checkin_at, r.route_date, r.sales_rep_id,
              c.name_ar AS customer_name, c.code AS customer_code,
              COALESCE(rep.name_ar, '') AS sales_rep_name
       FROM sal_rep_route_line l
       INNER JOIN sal_rep_route r ON r.id = l.route_id
       INNER JOIN crm_customer c ON c.id = l.customer_id
       LEFT JOIN crm_sales_rep rep ON rep.id = r.sales_rep_id
       WHERE l.id = ? LIMIT 1`,
      [id]
    );
    const row = rows[0];
    if (!row) return null;
    const ref = String(row.customer_code || '').trim() || `زيارة #${id}`;
    return { ...row, entity_ref: ref };
  } catch {
    return null;
  }
}

async function logVisitLineDelete(snapshot, userId) {
  if (!snapshot) return false;
  const summary = buildSummary({
    العميل: partyLabel(snapshot.customer_name, snapshot.customer_code),
    المندوب: snapshot.sales_rep_name || '',
    'تاريخ المسار': snapshot.route_date ? String(snapshot.route_date).slice(0, 10) : '',
    دخول: snapshot.visit_checkin_at ? String(snapshot.visit_checkin_at) : '',
  });
  return write({
    screenCode: 'report_sales_rep_visits',
    actionCode: 'delete',
    userId,
    entityType: 'sal_rep_route_line',
    entityId: Number(snapshot.line_id) || null,
    entityRef: snapshot.entity_ref,
    docDate: snapshot.route_date,
    summary,
  });
}


async function auditSafe(promise) {
  try {
    return await promise;
  } catch (e) {
    console.error('sysAuditLog.auditSafe', e.message);
    return false;
  }
}

async function logMaster({
  screenCode,
  actionCode,
  userId,
  entityType,
  entityId,
  entityRef,
  summary,
  domainCode,
  screenLabelAr,
  docDate,
} = {}) {
  return write({
    screenCode,
    actionCode,
    userId,
    entityType,
    entityId,
    entityRef,
    summary,
    domainCode,
    screenLabelAr,
    docDate,
  });
}

async function colExistsSimple(table, column) {
  try {
    await db.query(`SELECT \`${column}\` FROM \`${table}\` LIMIT 1`);
    return true;
  } catch {
    return false;
  }
}

function itemRowOrIdIsUpdatePair(o) {
  return o && typeof o === 'object' && o.before != null && o.after != null;
}

async function fetchItemSnapshot(itemIdOrRow) {
  if (itemIdOrRow && typeof itemIdOrRow === 'object' && !itemRowOrIdIsUpdatePair(itemIdOrRow)) {
    const id = Number(itemIdOrRow.id) || 0;
    if (id && itemIdOrRow.name_ar != null && itemIdOrRow.default_sale != null) {
      return itemIdOrRow;
    }
    if (!id && itemIdOrRow.name_ar) return itemIdOrRow;
  }
  const id = Number(itemIdOrRow) || 0;
  if (!id) return null;
  try {
    const hasWh = await colExistsSimple('inv_item', 'default_wholesale');
    const hasCost = await colExistsSimple('inv_item', 'default_cost');
    const hasActive = await colExistsSimple('inv_item', 'is_active');
    let cols = 'id, sku, barcode, name_ar, default_sale';
    if (hasCost) cols += ', default_cost';
    if (hasWh) cols += ', default_wholesale';
    if (hasActive) cols += ', is_active';
    const rows = await db.query(`SELECT ${cols} FROM inv_item WHERE id = ? LIMIT 1`, [id]);
    return rows[0] || null;
  } catch {
    return null;
  }
}

const ITEM_AUDIT_FIELD_MAP = {
  name_ar: 'المادة',
  sku: 'رمز',
  barcode: 'باركود',
  default_sale: 'بيع',
  default_wholesale: 'جملة',
  default_cost: 'تكلفة',
  is_active: 'الحالة',
};
const ITEM_AUDIT_IDENTITY = ['name_ar', 'sku'];

async function itemAuditDisplayValue(key, raw) {
  if (key === 'is_active') return Number(raw) === 1 ? 'مفعّل' : 'موقوف';
  if (key === 'default_sale' || key === 'default_wholesale' || key === 'default_cost') {
    return await formatMoney(raw);
  }
  return raw == null ? '' : String(raw).trim();
}

async function logItem(actionCode, itemRowOrId, userId, extraParts = null) {
  const isUpdatePair =
    actionCode === 'update' &&
    itemRowOrId &&
    typeof itemRowOrId === 'object' &&
    itemRowOrId.before != null &&
    itemRowOrId.after != null;

  if (isUpdatePair) {
    const before =
      typeof itemRowOrId.before === 'object'
        ? itemRowOrId.before
        : await fetchItemSnapshot(itemRowOrId.before);
    const after =
      typeof itemRowOrId.after === 'object'
        ? itemRowOrId.after
        : await fetchItemSnapshot(itemRowOrId.after);
    if (!before || !after) return false;
    const fieldMap = { ...ITEM_AUDIT_FIELD_MAP };
    if (
      after.default_wholesale == null &&
      before.default_wholesale == null &&
      !('default_wholesale' in after) &&
      !('default_wholesale' in before)
    ) {
      delete fieldMap.default_wholesale;
    }
    const displayCache = new Map();
    for (const row of [before, after]) {
      for (const key of Object.keys(fieldMap)) {
        const ck = `${key}:${normAuditVal(row[key])}`;
        if (!displayCache.has(ck)) {
          displayCache.set(ck, await itemAuditDisplayValue(key, row[key]));
        }
      }
    }
    const displayValueResolved = (key, raw) => {
      const ck = `${key}:${normAuditVal(raw)}`;
      return displayCache.get(ck) ?? '';
    };
    let summary = buildOldNewSummary(
      fieldMap,
      before,
      after,
      ITEM_AUDIT_IDENTITY,
      displayValueResolved
    );
    if (extraParts) {
      const extraSummary = buildSummary(extraParts);
      if (extraSummary) summary = summary ? `${summary} | ${extraSummary}` : extraSummary;
    }
    const ref = String(after.barcode || after.sku || after.id || '').trim();
    return logMaster({
      screenCode: 'items',
      actionCode,
      userId,
      entityType: 'inv_item',
      entityId: Number(after.id) || null,
      entityRef: ref,
      summary,
      domainCode: 'inventory',
    });
  }

  const row = await fetchItemSnapshot(itemRowOrId);
  if (!row) return false;
  const sale = await formatMoney(row.default_sale);
  let wholesale = '';
  if (row.default_wholesale != null) wholesale = await formatMoney(row.default_wholesale);
  const ref = String(row.barcode || row.sku || row.id || '').trim();
  const parts = {
    المادة: row.name_ar || '',
    رمز: row.sku || '',
    بيع: sale,
  };
  if (row.barcode) parts['باركود'] = row.barcode;
  if (wholesale !== '') parts['جملة'] = wholesale;
  if (extraParts) Object.assign(parts, extraParts);
  const summary = buildSummary(parts);
  return logMaster({
    screenCode: 'items',
    actionCode,
    userId,
    entityType: 'inv_item',
    entityId: Number(row.id) || null,
    entityRef: ref,
    summary,
    domainCode: 'inventory',
  });
}

async function fetchCustomerSnapshot(customerIdOrRow) {
  if (
    customerIdOrRow &&
    typeof customerIdOrRow === 'object' &&
    !itemRowOrIdIsUpdatePair(customerIdOrRow) &&
    customerIdOrRow.name_ar
  ) {
    return customerIdOrRow;
  }
  const id = Number(
    customerIdOrRow && typeof customerIdOrRow === 'object' ? customerIdOrRow.id : customerIdOrRow
  );
  if (!id) return null;
  try {
    const rows = await db.query(
      `SELECT id, code, name_ar, use_wholesale_price FROM crm_customer WHERE id = ? LIMIT 1`,
      [id]
    );
    return rows[0] || null;
  } catch {
    return null;
  }
}

const CUSTOMER_AUDIT_FIELD_MAP = {
  name_ar: 'الاسم',
  code: 'الرمز',
  use_wholesale_price: 'تسعير',
};

function customerPricingLabel(useWholesale) {
  return Number(useWholesale) === 1 ? 'جملة' : 'كبار';
}

async function logCustomer(actionCode, customerIdOrRow, userId) {
  const isUpdatePair =
    actionCode === 'update' &&
    customerIdOrRow &&
    typeof customerIdOrRow === 'object' &&
    customerIdOrRow.before != null &&
    customerIdOrRow.after != null;

  if (isUpdatePair) {
    const before =
      typeof customerIdOrRow.before === 'object'
        ? customerIdOrRow.before
        : await fetchCustomerSnapshot(customerIdOrRow.before);
    const after =
      typeof customerIdOrRow.after === 'object'
        ? customerIdOrRow.after
        : await fetchCustomerSnapshot(customerIdOrRow.after);
    if (!before || !after) return false;
    const displayValue = (key, raw) => {
      if (key === 'use_wholesale_price') return customerPricingLabel(raw);
      return raw == null ? '' : String(raw).trim();
    };
    const parts = { العميل: partyLabel(after.name_ar, after.code) };
    const innerParts = { 'القديم': [], 'الجديد': [] };
    for (const [key, label] of Object.entries(CUSTOMER_AUDIT_FIELD_MAP)) {
      if (normAuditVal(before[key]) === normAuditVal(after[key])) continue;
      const ov = displayValue(key, before[key]);
      const nv = displayValue(key, after[key]);
      innerParts['القديم'].push(`${label}=${ov || '—'}`);
      innerParts['الجديد'].push(`${label}=${nv || '—'}`);
    }
    if (innerParts['القديم'].length) parts['القديم'] = innerParts['القديم'].join('، ');
    if (innerParts['الجديد'].length) parts['الجديد'] = innerParts['الجديد'].join('، ');
    const summary = buildSummary(parts);
    return logMaster({
      screenCode: 'customers',
      actionCode,
      userId,
      entityType: 'crm_customer',
      entityId: Number(after.id) || null,
      entityRef: String(after.code || after.name_ar || '').trim(),
      summary,
      domainCode: 'customers',
    });
  }

  const row = await fetchCustomerSnapshot(customerIdOrRow);
  if (!row) return false;
  const wholesale =
    Number(row.use_wholesale_price) === 1 ? 'سعر جملة من البطاقة' : 'سعر بيع تجزئة';
  const summary = buildSummary({
    العميل: partyLabel(row.name_ar, row.code),
    التسعير: wholesale,
  });
  return logMaster({
    screenCode: 'customers',
    actionCode,
    userId,
    entityType: 'crm_customer',
    entityId: Number(row.id) || null,
    entityRef: String(row.code || row.name_ar || '').trim(),
    summary,
    domainCode: 'customers',
  });
}

async function fetchPartyRow(table, id) {
  const partyId = Number(id) || 0;
  if (!partyId) return null;
  try {
    const rows = await db.query(`SELECT id, code, name_ar FROM \`${table}\` WHERE id = ? LIMIT 1`, [partyId]);
    return rows[0] || null;
  } catch {
    return null;
  }
}

async function logPartyMaster({ screenCode, entityType, domainCode, actionCode, rowOrId, userId, table }) {
  let row = rowOrId;
  if (!row || typeof row !== 'object' || !row.name_ar) {
    row = await fetchPartyRow(table, rowOrId);
  }
  if (!row) return false;
  const summary = buildSummary({
    الاسم: partyLabel(row.name_ar, row.code),
  });
  return logMaster({
    screenCode,
    actionCode,
    userId,
    entityType,
    entityId: Number(row.id) || null,
    entityRef: String(row.code || row.name_ar || '').trim(),
    summary,
    domainCode,
  });
}

async function logSupplier(actionCode, rowOrId, userId) {
  return logPartyMaster({
    screenCode: 'suppliers',
    entityType: 'crm_supplier',
    domainCode: 'suppliers',
    actionCode,
    rowOrId,
    userId,
    table: 'crm_supplier',
  });
}

async function logSalesRep(actionCode, rowOrId, userId) {
  return logPartyMaster({
    screenCode: 'sales_reps',
    entityType: 'crm_sales_rep',
    domainCode: 'sales_reps',
    actionCode,
    rowOrId,
    userId,
    table: 'crm_sales_rep',
  });
}

async function logWarehouse(actionCode, rowOrId, userId) {
  return logPartyMaster({
    screenCode: 'warehouses',
    entityType: 'inv_warehouse',
    domainCode: 'inventory',
    actionCode,
    rowOrId,
    userId,
    table: 'inv_warehouse',
  });
}

async function logNamedMaster({ screenCode, entityType, actionCode, rowOrId, userId, table, labelKey }) {
  let row = rowOrId;
  if (!row || typeof row !== 'object' || !row.name_ar) {
    const id = Number(rowOrId) || 0;
    if (!id) return false;
    try {
      const rows = await db.query(`SELECT id, code, name_ar FROM \`${table}\` WHERE id = ? LIMIT 1`, [id]);
      row = rows[0];
    } catch {
      row = null;
    }
  }
  if (!row) return false;
  const summary = buildSummary({
    [labelKey || 'الاسم']: partyLabel(row.name_ar, row.code),
  });
  return logMaster({
    screenCode,
    actionCode,
    userId,
    entityType,
    entityId: Number(row.id) || null,
    entityRef: String(row.code || row.name_ar || '').trim(),
    summary,
    domainCode: 'inventory',
  });
}

async function logPriceAdjustDoc(actionCode, docId, userId) {
  const id = Number(docId) || 0;
  if (!id) return false;
  try {
    const docs = await db.query(
      `SELECT id, adj_no, adj_date, status FROM inv_price_adj_doc WHERE id = ? LIMIT 1`,
      [id]
    );
    const doc = docs[0];
    if (!doc) return false;
    const lines = await db.query(`SELECT COUNT(*) AS c FROM inv_item_sale_price_adj WHERE doc_id = ?`, [id]);
    const lineCount = Number(lines[0]?.c || 0);
    const summary = buildSummary({
      الحركة: doc.adj_no || '',
      البنود: String(lineCount),
      الحالة: String(doc.status) === 'posted' ? 'مرحّلة' : 'مسودة',
    });
    return logMaster({
      screenCode: 'item_sale_price_adjust',
      actionCode,
      userId,
      entityType: 'inv_price_adj_doc',
      entityId: id,
      entityRef: doc.adj_no,
      docDate: doc.adj_date,
      summary,
      domainCode: 'inventory',
    });
  } catch (e) {
    console.error('logPriceAdjustDoc', e.message);
    return false;
  }
}

module.exports = {
  write,
  logCustomerOrder,
  fetchCustomerOrderSnapshot,
  logCustomerOrderSnapshot,
  fetchVisitLineSnapshot,
  logVisitLineDelete,
  buildSummary,
  buildOldNewSummary,
  normAuditVal,
  ensureSchema,
  ACTION_LABELS,
  auditSafe,
  logMaster,
  fetchItemSnapshot,
  fetchCustomerSnapshot,
  logItem,
  logCustomer,
  logSupplier,
  logSalesRep,
  logWarehouse,
  logNamedMaster,
  logPriceAdjustDoc,
};
