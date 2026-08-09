'use strict';

const db = require('../db');
const { todayIso } = require('../lib/html');

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('system query', e.message);
    return [];
  }
}

function dateRange(from, to) {
  return { from: from || monthStart(), to: to || todayIso() };
}

async function listUsers({ q = '', activeOnly = false } = {}) {
  const where = ['1=1'];
  const params = [];
  if (activeOnly) where.push('u.is_active = 1');
  if (q) {
    const like = `%${q}%`;
    where.push(
      `(u.username LIKE ? OR IFNULL(u.full_name_ar,'') LIKE ? OR IFNULL(u.email,'') LIKE ?)`
    );
    params.push(like, like, like);
  }
  return safeQuery(
    `SELECT u.id, u.username, u.full_name_ar, u.email, u.is_active, u.sales_rep_id, u.ui_theme, u.created_at,
            (SELECT GROUP_CONCAT(g.name_ar ORDER BY g.name_ar SEPARATOR '، ')
             FROM sys_user_group ug INNER JOIN sys_group g ON g.id = ug.group_id
             WHERE ug.user_id = u.id) AS groups_ar,
            r.name_ar AS sales_rep_name
     FROM sys_user u
     LEFT JOIN crm_sales_rep r ON r.id = u.sales_rep_id
     WHERE ${where.join(' AND ')}
     ORDER BY u.id DESC
     LIMIT 300`,
    params
  );
}

async function listGroups() {
  return safeQuery(
    `SELECT g.id, g.code, g.name_ar, g.description,
            (SELECT COUNT(*) FROM sys_user_group ug WHERE ug.group_id = g.id) AS user_count,
            (SELECT COUNT(*) FROM sys_group_permission gp WHERE gp.group_id = g.id AND gp.allowed = 1) AS perm_count
     FROM sys_group g
     ORDER BY g.name_ar, g.id`
  );
}

async function listPermissionsMatrix(groupId = 0) {
  const groups = await listGroups();
  const screens = await safeQuery(
    `SELECT id, code, name_ar, screen_type, sort_order FROM sys_screen ORDER BY sort_order, id`
  );
  let allowed = new Set();
  if (groupId > 0) {
    const rows = await safeQuery(
      `SELECT screen_id FROM sys_group_permission WHERE group_id = ? AND allowed = 1`,
      [groupId]
    );
    allowed = new Set(rows.map((r) => Number(r.screen_id)));
  }
  return { groups, screens, allowed };
}

async function listOpenSessions() {
  return safeQuery(
    `SELECT s.*, u.username, u.full_name_ar
     FROM sys_user_open_session s
     LEFT JOIN sys_user u ON u.id = s.user_id
     ORDER BY s.id DESC
     LIMIT 200`
  );
}

async function companySettings() {
  const rows = await safeQuery(`SELECT * FROM sys_company_settings WHERE id = 1 LIMIT 1`);
  return rows[0] || null;
}

async function listTaxRates() {
  return safeQuery(
    `SELECT id, name_ar, rate_percent, is_active, sort_order FROM sys_tax_rate ORDER BY sort_order, id`
  );
}

async function listDashboardAccounts() {
  return safeQuery(
    `SELECT d.account_id, d.is_visible, d.updated_at, a.code AS account_code, a.name_ar AS account_name
     FROM sys_dashboard_account d
     LEFT JOIN acc_account a ON a.id = d.account_id
     ORDER BY a.code, d.account_id`
  );
}

async function einvoiceSettings() {
  const rows = await safeQuery(`SELECT * FROM sys_einvoice_settings LIMIT 1`);
  return rows[0] || null;
}

async function listAuditLog({ q = '', from, to } = {}) {
  const r = dateRange(from, to);
  const where = ['DATE(a.logged_at) BETWEEN ? AND ?'];
  const params = [r.from, r.to];
  if (q) {
    const like = `%${q}%`;
    where.push(
      `(IFNULL(a.summary,'') LIKE ? OR IFNULL(a.screen_label_ar,'') LIKE ? OR IFNULL(a.action_label_ar,'') LIKE ? OR IFNULL(a.entity_ref,'') LIKE ? OR IFNULL(u.username,'') LIKE ?)`
    );
    params.push(like, like, like, like, like);
  }
  return safeQuery(
    `SELECT a.id, a.logged_at, a.domain_code, a.screen_code, a.screen_label_ar, a.action_code,
            a.action_label_ar, a.entity_type, a.entity_id, a.entity_ref, a.summary,
            u.username, u.full_name_ar
     FROM sys_audit_log a
     LEFT JOIN sys_user u ON u.id = a.user_id
     WHERE ${where.join(' AND ')}
     ORDER BY a.id DESC
     LIMIT 300`,
    params
  );
}

async function listErrorLog({ q = '', from, to } = {}) {
  const r = dateRange(from, to);
  const where = ['DATE(e.logged_at) BETWEEN ? AND ?'];
  const params = [r.from, r.to];
  if (q) {
    const like = `%${q}%`;
    where.push(
      `(IFNULL(e.message,'') LIKE ? OR IFNULL(e.username,'') LIKE ? OR IFNULL(e.screen_code,'') LIKE ? OR IFNULL(e.source,'') LIKE ?)`
    );
    params.push(like, like, like, like);
  }
  return safeQuery(
    `SELECT e.id, e.logged_at, e.last_seen_at, e.source, e.level, e.message, e.screen_code,
            e.username, e.ip_address, e.occurrence_count, e.request_uri
     FROM sys_error_log e
     WHERE ${where.join(' AND ')}
     ORDER BY e.id DESC
     LIMIT 250`,
    params
  );
}

async function listInvoiceGps({ from, to } = {}) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT i.id, i.invoice_no, i.invoice_date, i.post_latitude, i.post_longitude,
            i.post_gps_accuracy, i.post_gps_at, i.post_gps_place, c.name_ar AS customer_name
     FROM sal_invoice i
     LEFT JOIN crm_customer c ON c.id = i.customer_id
     WHERE i.post_latitude IS NOT NULL AND i.post_longitude IS NOT NULL
       AND i.invoice_date BETWEEN ? AND ?
     ORDER BY i.id DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

async function listUserLocations() {
  return safeQuery(
    `SELECT l.*, u.username, u.full_name_ar
     FROM sys_user_location l
     LEFT JOIN sys_user u ON u.id = l.user_id
     ORDER BY l.id DESC
     LIMIT 200`
  );
}

async function listGpsTracks({ from, to } = {}) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT t.id, t.user_id, t.latitude, t.longitude, t.gps_accuracy, t.gps_source, t.captured_at,
            u.username, u.full_name_ar
     FROM sys_user_location_track t
     LEFT JOIN sys_user u ON u.id = t.user_id
     WHERE DATE(t.captured_at) BETWEEN ? AND ?
     ORDER BY t.id DESC
     LIMIT 300`,
    [r.from, r.to]
  );
}

module.exports = {
  listUsers,
  listGroups,
  listPermissionsMatrix,
  listOpenSessions,
  companySettings,
  listTaxRates,
  listDashboardAccounts,
  einvoiceSettings,
  listAuditLog,
  listErrorLog,
  listInvoiceGps,
  listUserLocations,
  listGpsTracks,
  dateRange,
};
