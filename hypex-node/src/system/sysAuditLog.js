'use strict';

const db = require('../db');
const companyDecimals = require('../lib/companyDecimals');

const ACTION_LABELS = {
  save: 'حفظ',
  delete: 'حذف',
  post: 'ترحيل',
  unpost: 'فك ترحيل',
  cancel: 'إلغاء',
  approve: 'اعتماد',
  unapprove: 'فك اعتماد',
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

module.exports = {
  write,
  logCustomerOrder,
  fetchCustomerOrderSnapshot,
  logCustomerOrderSnapshot,
  fetchVisitLineSnapshot,
  logVisitLineDelete,
  buildSummary,
  ensureSchema,
};
