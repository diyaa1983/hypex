'use strict';

const db = require('../db');

let ensured = false;

async function ensureInboxTable() {
  if (ensured) return;
  try {
    const pool = db.getPool();
    await pool.query(`
      CREATE TABLE IF NOT EXISTS sys_user_inbox (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        kind VARCHAR(40) NOT NULL,
        title VARCHAR(190) NOT NULL,
        body VARCHAR(500) NULL DEFAULT NULL,
        ref_type VARCHAR(40) NULL DEFAULT NULL,
        ref_id INT UNSIGNED NULL DEFAULT NULL,
        customer_id INT UNSIGNED NULL DEFAULT NULL,
        payload_json TEXT NULL DEFAULT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_sui_user_read (user_id, is_read, created_at),
        KEY idx_sui_ref (ref_type, ref_id, kind, user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);
    ensured = true;
  } catch (e) {
    console.error('ensureInboxTable', e.message);
  }
}

async function recipientIds(row) {
  const ids = new Set();
  const reqBy = Number(row.requested_by || 0);
  if (reqBy > 0) ids.add(reqBy);

  let repId = Number(row.sales_rep_id || 0);
  const customerId = Number(row.customer_id || 0);
  if (!repId && customerId > 0) {
    try {
      const cust = await db.query('SELECT sales_rep_id FROM crm_customer WHERE id = ? LIMIT 1', [
        customerId,
      ]);
      repId = Number((cust && cust[0] && cust[0].sales_rep_id) || 0);
    } catch (_) {
      /* */
    }
  }
  if (repId > 0) {
    try {
      const users = await db.query('SELECT id FROM sys_user WHERE sales_rep_id = ?', [repId]);
      for (const u of users || []) {
        const id = Number(u.id || 0);
        if (id > 0) ids.add(id);
      }
    } catch (_) {
      /* عمود sales_rep_id قد لا يوجد */
    }
  }
  return [...ids];
}

async function push(userId, kind, title, body, meta = {}) {
  const uid = Number(userId || 0);
  if (uid < 1) return;
  await ensureInboxTable();
  const refType = String(meta.ref_type || '');
  const refId = Number(meta.ref_id || 0);
  if (refType && refId > 0) {
    const dup = await db.query(
      `SELECT id FROM sys_user_inbox
       WHERE user_id = ? AND kind = ? AND ref_type = ? AND ref_id = ? LIMIT 1`,
      [uid, kind, refType, refId]
    );
    if (dup && dup[0]) return;
  }
  await db.query(
    `INSERT INTO sys_user_inbox
     (user_id, kind, title, body, ref_type, ref_id, customer_id, payload_json)
     VALUES (?,?,?,?,?,?,?,?)`,
    [
      uid,
      kind,
      title,
      body || null,
      refType || null,
      refId > 0 ? refId : null,
      Number(meta.customer_id || 0) || null,
      meta.payload ? JSON.stringify(meta.payload) : null,
    ]
  );
}

async function pushGpsDecision(changeRow, approve) {
  try {
    await ensureInboxTable();
    const customerId = Number(changeRow.customer_id || 0);
    let name = '';
    let code = '';
    if (customerId > 0) {
      const rows = await db.query(
        'SELECT name_ar, code FROM crm_customer WHERE id = ? LIMIT 1',
        [customerId]
      );
      if (rows && rows[0]) {
        name = String(rows[0].name_ar || '');
        code = String(rows[0].code || '');
      }
    }
    let who = name ? `«${name}»` : 'عميل';
    if (code) who += ` (${code})`;
    const kind = approve ? 'gps_change_approved' : 'gps_change_rejected';
    const title = approve
      ? 'تمت الموافقة على موقع العميل'
      : 'رُفض تعديل موقع العميل';
    const body = approve
      ? `تم اعتماد تحديد موقع العميل ${who}.`
      : `تم رفض طلب تعديل موقع العميل ${who}.`;
    const payload = {
      customer_name: name,
      customer_code: code,
      latitude: changeRow.new_latitude ?? null,
      longitude: changeRow.new_longitude ?? null,
      clear_gps: Number(changeRow.clear_gps || 0) === 1,
    };
    const users = await recipientIds(changeRow);
    if (!users.length) {
      console.error('pushGpsDecision: no recipients', {
        id: changeRow.id,
        requested_by: changeRow.requested_by,
        sales_rep_id: changeRow.sales_rep_id,
      });
      return;
    }
    for (const uid of users) {
      try {
        await push(uid, kind, title, body, {
          ref_type: 'crm_customer_gps_change',
          ref_id: Number(changeRow.id || 0),
          customer_id: customerId,
          payload,
        });
      } catch (e) {
        console.error('inbox push', e.message);
      }
    }
  } catch (e) {
    console.error('pushGpsDecision', e.message);
  }
}

async function pushCheckoutDecision(reqRow, approve) {
  try {
    await ensureInboxTable();
    const customerId = Number(reqRow.customer_id || 0);
    let name = '';
    let code = '';
    if (customerId > 0) {
      const rows = await db.query(
        'SELECT name_ar, code FROM crm_customer WHERE id = ? LIMIT 1',
        [customerId]
      );
      if (rows && rows[0]) {
        name = String(rows[0].name_ar || '');
        code = String(rows[0].code || '');
      }
    }
    let who = name ? `«${name}»` : 'العميل';
    if (code) who += ` (${code})`;
    const kind = approve ? 'visit_checkout_approved' : 'visit_checkout_rejected';
    const title = approve
      ? 'تمت الموافقة على الخروج اليدوي'
      : 'رُفض طلب الخروج اليدوي';
    const body = approve
      ? `تم اعتماد خروجك اليدوي من زيارة ${who}.`
      : `تم رفض طلب الخروج اليدوي من زيارة ${who}. الزيارة ما زالت مفتوحة.`;
    const payload = {
      customer_name: name,
      customer_code: code,
    };
    const users = await recipientIds(reqRow);
    if (!users.length) {
      console.error('pushCheckoutDecision: no recipients', {
        id: reqRow.id,
        requested_by: reqRow.requested_by,
        sales_rep_id: reqRow.sales_rep_id,
      });
      return;
    }
    for (const uid of users) {
      try {
        await push(uid, kind, title, body, {
          ref_type: 'sal_rep_visit_checkout_request',
          ref_id: Number(reqRow.id || 0),
          customer_id: customerId,
          payload,
        });
      } catch (e) {
        console.error('inbox push', e.message);
      }
    }
  } catch (e) {
    console.error('pushCheckoutDecision', e.message);
  }
}

function mapItem(row) {
  let payload = {};
  const raw = String(row.payload_json || '');
  if (raw) {
    try {
      const decoded = JSON.parse(raw);
      if (decoded && typeof decoded === 'object') payload = decoded;
    } catch (_) {
      /* */
    }
  }
  return {
    id: Number(row.id || 0),
    kind: String(row.kind || ''),
    title: String(row.title || ''),
    body: String(row.body || ''),
    ref_type: String(row.ref_type || ''),
    ref_id: Number(row.ref_id || 0),
    customer_id: Number(row.customer_id || 0),
    is_read: Number(row.is_read || 0) === 1,
    created_at: String(row.created_at || ''),
    customer_name: String(payload.customer_name || ''),
    customer_code: String(payload.customer_code || ''),
    latitude: payload.latitude != null ? Number(payload.latitude) : null,
    longitude: payload.longitude != null ? Number(payload.longitude) : null,
    clear_gps: !!payload.clear_gps,
  };
}

async function listForUser(userId, limit = 50) {
  await ensureInboxTable();
  const uid = Number(userId || 0);
  if (uid < 1) return [];
  const lim = Math.max(1, Math.min(100, Number(limit) || 50));
  const rows = await db.query(
    `SELECT id, kind, title, body, ref_type, ref_id, customer_id, payload_json,
            is_read, created_at
     FROM sys_user_inbox
     WHERE user_id = ?
     ORDER BY is_read ASC, created_at DESC, id DESC
     LIMIT ${lim}`,
    [uid]
  );
  return (rows || []).map(mapItem);
}

async function unreadCount(userId) {
  await ensureInboxTable();
  const uid = Number(userId || 0);
  if (uid < 1) return 0;
  const rows = await db.query(
    'SELECT COUNT(*) AS c FROM sys_user_inbox WHERE user_id = ? AND is_read = 0',
    [uid]
  );
  return Number((rows && rows[0] && rows[0].c) || 0);
}

async function markAllRead(userId) {
  await ensureInboxTable();
  const uid = Number(userId || 0);
  if (uid < 1) return 0;
  await db.query(
    'UPDATE sys_user_inbox SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0',
    [uid]
  );
  return 1;
}

async function userIdFromDevice(deviceId) {
  const id = String(deviceId || '').trim();
  if (!id) return 0;
  try {
    const rows = await db.query(
      `SELECT user_id FROM sys_user_mobile_device_lock
       WHERE device_id = ? AND heartbeat_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
       LIMIT 1`,
      [id]
    );
    return Number((rows && rows[0] && rows[0].user_id) || 0);
  } catch (_) {
    return 0;
  }
}

module.exports = {
  ensureInboxTable,
  pushGpsDecision,
  pushCheckoutDecision,
  listForUser,
  unreadCount,
  markAllRead,
  userIdFromDevice,
};
