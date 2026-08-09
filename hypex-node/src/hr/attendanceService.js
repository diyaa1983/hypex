'use strict';

const db = require('../db');
const { todayIso } = require('../lib/html');

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

async function q(sql, params = []) {
  return db.query(sql, params);
}

async function safe(sql, params = []) {
  try {
    return await q(sql, params);
  } catch (e) {
    console.error('attendance', e.message);
    return [];
  }
}

function parseRange(fromIn, toIn) {
  let from = String(fromIn || '').trim();
  let to = String(toIn || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(from)) from = monthStart();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(to)) to = todayIso();
  if (from > to) [from, to] = [to, from];
  return { from, to };
}

function punchTypeLabel(type) {
  const t = String(type || '')
    .trim()
    .toUpperCase();
  if (t === 'I' || t === '0') return 'دخول';
  if (t === 'O' || t === '1') return 'خروج';
  return t || '—';
}

function verifyLabel(code) {
  const c = code == null || code === '' ? null : Number(code);
  if (c === 1) return 'بصمة';
  if (c === 2) return 'بطاقة';
  if (c === 3) return 'بصمة+بطاقة';
  if (c === 4) return 'وجه';
  if (c === 0) return 'كلمة مرور';
  if (c != null && Number.isFinite(c) && c > 0) return String(c);
  return '—';
}

function normalizeBadge(badge) {
  return String(badge || '')
    .trim()
    .replace(/^0+/, '') || String(badge || '').trim();
}

async function getConfig() {
  const rows = await safe(
    `SELECT mdb_path, last_sync_at, last_punch_time, sync_token FROM hr_att_config WHERE id = 1 LIMIT 1`
  );
  return (
    rows[0] || {
      mdb_path: '',
      last_sync_at: null,
      last_punch_time: null,
      sync_token: '',
    }
  );
}

function generateSyncToken() {
  return require('crypto').randomBytes(32).toString('hex');
}

async function ensureSyncToken() {
  const cfg = await getConfig();
  const t = String(cfg.sync_token || '').trim();
  if (t) return t;
  return regenerateSyncToken();
}

async function regenerateSyncToken() {
  const token = generateSyncToken();
  try {
    await q(
      `INSERT INTO hr_att_config (id, mdb_path, sync_token) VALUES (1, '', ?)
       ON DUPLICATE KEY UPDATE sync_token = VALUES(sync_token)`,
      [token]
    );
  } catch (e) {
    await q(`UPDATE hr_att_config SET sync_token = ? WHERE id = 1`, [token]);
  }
  return token;
}

async function saveMdbPath(pathIn) {
  const path = String(pathIn || '').trim();
  if (!path) return { ok: false, error: 'أدخل مسار att2000.mdb.' };
  try {
    await q(
      `INSERT INTO hr_att_config (id, mdb_path) VALUES (1, ?)
       ON DUPLICATE KEY UPDATE mdb_path = VALUES(mdb_path)`,
      [path]
    );
    return { ok: true, message: 'تم حفظ مسار قاعدة البصمة.' };
  } catch (e) {
    return { ok: false, error: 'تعذر الحفظ: ' + (e.message || '') };
  }
}

async function countPunches() {
  const rows = await safe(`SELECT COUNT(*) AS c FROM hr_att_punch`);
  return Number(rows[0]?.c || 0);
}

async function listEmployeesActive() {
  return safe(
    `SELECT id, emp_code, name_ar FROM hr_employee
     WHERE is_active = 1
       AND (resignation_date IS NULL OR resignation_date <= '1000-01-01'
            OR COALESCE(is_resigned_posted,0) = 0)
     ORDER BY CAST(IFNULL(emp_code,'0') AS UNSIGNED), name_ar
     LIMIT 1000`
  );
}

async function listPunches({ from, to, employeeId = 0, limit = 500 } = {}) {
  const r = parseRange(from, to);
  const where = ['p.punch_time >= ?', 'p.punch_time <= ?'];
  const params = [r.from + ' 00:00:00', r.to + ' 23:59:59'];
  const eid = Number(employeeId) || 0;
  if (eid > 0) {
    where.push('p.employee_id = ?');
    params.push(eid);
  }
  const lim = Math.max(1, Math.min(2000, Number(limit) || 500));
  const rows = await safe(
    `SELECT p.id, p.employee_id, p.zk_user_id, p.badge_number, p.zk_name,
            p.punch_time, p.punch_type, p.verify_code, p.sensor_id,
            e.name_ar AS employee_name, e.emp_code
     FROM hr_att_punch p
     LEFT JOIN hr_employee e ON e.id = p.employee_id
     WHERE ${where.join(' AND ')}
     ORDER BY p.punch_time DESC
     LIMIT ${lim}`,
    params
  );
  return { rows, ...r, employeeId: eid };
}

async function listMapped(limit = 500) {
  const lim = Math.max(1, Math.min(1000, limit));
  return safe(
    `SELECT m.zk_user_id, m.badge_number, m.zk_name, m.employee_id,
            e.emp_code, e.name_ar AS emp_name,
            (SELECT COUNT(*) FROM hr_att_punch p WHERE p.zk_user_id = m.zk_user_id) AS punch_count,
            (SELECT MAX(p.punch_time) FROM hr_att_punch p WHERE p.zk_user_id = m.zk_user_id) AS last_punch
     FROM hr_att_employee_map m
     INNER JOIN hr_employee e ON e.id = m.employee_id
     ORDER BY e.name_ar ASC, m.zk_user_id ASC
     LIMIT ${lim}`
  );
}

async function listUnmappedZk(limit = 300) {
  const lim = Math.max(1, Math.min(500, limit));
  return safe(
    `SELECT p.zk_user_id,
            MAX(p.badge_number) AS badge_number,
            MAX(p.zk_name) AS zk_name,
            COUNT(*) AS punch_count,
            MAX(p.punch_time) AS last_punch
     FROM hr_att_punch p
     LEFT JOIN hr_att_employee_map m ON m.zk_user_id = p.zk_user_id
     WHERE (p.employee_id IS NULL OR p.employee_id = 0)
       AND m.id IS NULL
     GROUP BY p.zk_user_id
     ORDER BY last_punch DESC
     LIMIT ${lim}`
  );
}

async function listAvailableEmployeesForLink() {
  return safe(
    `SELECT e.id, e.emp_code, e.name_ar
     FROM hr_employee e
     WHERE e.is_active = 1
       AND e.id NOT IN (SELECT employee_id FROM hr_att_employee_map WHERE employee_id IS NOT NULL)
     ORDER BY CAST(IFNULL(e.emp_code,'0') AS UNSIGNED), e.name_ar
     LIMIT 500`
  );
}

async function findEmployeeIdByBadge(zkUserId, badge) {
  const zk = Number(zkUserId) || 0;
  if (zk > 0) {
    const m = await safe(
      `SELECT employee_id FROM hr_att_employee_map WHERE zk_user_id = ? LIMIT 1`,
      [zk]
    );
    if (m[0] && Number(m[0].employee_id) > 0) return Number(m[0].employee_id);
  }
  const b = String(badge || '').trim();
  if (!b) return null;
  const candidates = [...new Set([b, normalizeBadge(b)].filter(Boolean))];
  for (const code of candidates) {
    const rows = await safe(
      `SELECT id FROM hr_employee WHERE TRIM(emp_code) = ? AND is_active = 1 LIMIT 1`,
      [code]
    );
    if (rows[0]) return Number(rows[0].id);
  }
  return null;
}

async function upsertMap(zkUserId, employeeId, badge, zkName) {
  const zk = Number(zkUserId);
  const emp = Number(employeeId);
  if (zk < 1 || emp < 1) return;
  await q(
    `INSERT INTO hr_att_employee_map (zk_user_id, badge_number, employee_id, zk_name)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       badge_number = VALUES(badge_number),
       employee_id = VALUES(employee_id),
       zk_name = VALUES(zk_name)`,
    [zk, badge || null, emp, zkName || null]
  );
  await q(`UPDATE hr_att_punch SET employee_id = ? WHERE zk_user_id = ?`, [emp, zk]);
}

async function saveManualMap(zkUserId, employeeId) {
  const zk = Number(zkUserId);
  const emp = Number(employeeId);
  if (zk < 1) return { ok: false, error: 'رقم مستخدم البصمة غير صالح.' };
  if (emp < 1) return { ok: false, error: 'اختر موظفاً من النظام.' };

  const empRows = await safe(`SELECT id FROM hr_employee WHERE id = ? AND is_active = 1 LIMIT 1`, [
    emp,
  ]);
  if (!empRows[0]) return { ok: false, error: 'الموظف غير موجود أو غير نشط.' };

  const conflict = await safe(
    `SELECT m.zk_user_id, m.badge_number, e.name_ar
     FROM hr_att_employee_map m
     LEFT JOIN hr_employee e ON e.id = m.employee_id
     WHERE m.employee_id = ? AND m.zk_user_id <> ?
     LIMIT 1`,
    [emp, zk]
  );
  if (conflict[0]) {
    const name = String(conflict[0].name_ar || '').trim();
    const badge = String(conflict[0].badge_number || '').trim();
    return {
      ok: false,
      error:
        (name ? 'الموظف «' + name + '»' : 'هذا الموظف') +
        ' مربوط مسبقاً برقم بصمة آخر' +
        (badge ? ' (' + badge + ')' : '') +
        '. أزل الربط القديم أولاً.',
    };
  }

  // enrich from punches
  const meta = await safe(
    `SELECT badge_number, zk_name FROM hr_att_punch
     WHERE zk_user_id = ? AND (badge_number IS NOT NULL OR zk_name IS NOT NULL)
     ORDER BY punch_time DESC LIMIT 1`,
    [zk]
  );
  const badge = String(meta[0]?.badge_number || '').trim();
  const zkName = String(meta[0]?.zk_name || '').trim();

  try {
    await upsertMap(zk, emp, badge, zkName);
    return { ok: true, message: 'تم ربط الموظف بالبصمة.' };
  } catch (e) {
    return { ok: false, error: 'تعذر الربط: ' + (e.message || '') };
  }
}

async function unmap(zkUserId) {
  const zk = Number(zkUserId);
  if (zk < 1) return { ok: false, error: 'رقم غير صالح.' };
  const rows = await safe(`SELECT id FROM hr_att_employee_map WHERE zk_user_id = ? LIMIT 1`, [zk]);
  if (!rows[0]) return { ok: false, error: 'لا يوجد ربط لهذا المستخدم.' };
  try {
    await q(`DELETE FROM hr_att_employee_map WHERE zk_user_id = ?`, [zk]);
    await q(`UPDATE hr_att_punch SET employee_id = NULL WHERE zk_user_id = ?`, [zk]);
    return { ok: true, message: 'تم إلغاء الربط.' };
  } catch (e) {
    return { ok: false, error: e.message || 'تعذر إلغاء الربط.' };
  }
}

async function autoMap() {
  const rows = await safe(
    `SELECT DISTINCT zk_user_id, badge_number, zk_name
     FROM hr_att_punch
     WHERE employee_id IS NULL OR employee_id = 0`
  );
  let mapped = 0;
  for (const row of rows) {
    const zk = Number(row.zk_user_id || 0);
    if (zk < 1) continue;
    const empId = await findEmployeeIdByBadge(zk, row.badge_number);
    if (!empId) continue;
    await upsertMap(zk, empId, String(row.badge_number || '').trim(), String(row.zk_name || '').trim());
    mapped++;
  }
  return {
    ok: true,
    mapped,
    message: mapped > 0 ? `تم ربط ${mapped} مستخدم بصمة تلقائياً.` : 'لم يُعثر على تطابقات للربط التلقائي.',
  };
}

function badgeMatchesCode(badge, empCode) {
  const b = normalizeBadge(badge);
  const c = normalizeBadge(empCode);
  if (!b || !c) return false;
  return b === c || String(badge).trim() === String(empCode).trim();
}

module.exports = {
  parseRange,
  punchTypeLabel,
  verifyLabel,
  getConfig,
  ensureSyncToken,
  regenerateSyncToken,
  saveMdbPath,
  countPunches,
  listEmployeesActive,
  listPunches,
  listMapped,
  listUnmappedZk,
  listAvailableEmployeesForLink,
  saveManualMap,
  unmap,
  autoMap,
  badgeMatchesCode,
};
