'use strict';

const bcrypt = require('bcryptjs');
const db = require('../db');

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('system users', e.message);
    throw e;
  }
}

function hashPassword(plain) {
  // bcryptjs → $2a$; PHP password_verify accepts $2a$/$2y$ interchangeably via auth
  return bcrypt.hashSync(String(plain), 10);
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
    `SELECT u.id, u.username, u.full_name_ar, u.email, u.is_active, u.sales_rep_id, u.created_at,
            (SELECT GROUP_CONCAT(g.name_ar ORDER BY g.name_ar SEPARATOR '، ')
             FROM sys_user_group ug INNER JOIN sys_group g ON g.id = ug.group_id
             WHERE ug.user_id = u.id) AS groups_ar,
            r.name_ar AS sales_rep_name
     FROM sys_user u
     LEFT JOIN crm_sales_rep r ON r.id = u.sales_rep_id
     WHERE ${where.join(' AND ')}
     ORDER BY u.id ASC
     LIMIT 500`,
    params
  );
}

async function listGroups() {
  return safeQuery(
    `SELECT id, code, name_ar, description FROM sys_group ORDER BY name_ar, id`
  );
}

async function listSalesReps() {
  try {
    return await safeQuery(
      `SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar LIMIT 300`
    );
  } catch {
    return [];
  }
}

async function getUser(id) {
  const rows = await safeQuery(
    `SELECT id, username, full_name_ar, email, sales_rep_id, is_active
     FROM sys_user WHERE id = ? LIMIT 1`,
    [Number(id)]
  );
  if (!rows[0]) return null;
  const groupIds = await safeQuery(
    `SELECT group_id FROM sys_user_group WHERE user_id = ?`,
    [rows[0].id]
  );
  return {
    ...rows[0],
    group_ids: groupIds.map((g) => Number(g.group_id)),
  };
}

async function saveUser(payload, currentUserId) {
  const id = Number(payload.id || 0);
  const username = String(payload.username || '').trim();
  const fullName = String(payload.full_name_ar || '').trim();
  const email = String(payload.email || '').trim();
  const isActive =
    payload.is_active === '1' ||
    payload.is_active === 1 ||
    payload.is_active === true ||
    payload.is_active === 'on'
      ? 1
      : 0;
  const password = String(payload.password || '');
  const passwordConfirm = String(payload.password_confirm || '');
  let salesRepId = Number(payload.sales_rep_id || 0) || null;
  if (salesRepId !== null && salesRepId < 1) salesRepId = null;

  let groupIds = [];
  if (Array.isArray(payload.group_ids)) {
    groupIds = payload.group_ids.map(Number).filter((n) => n > 0);
  } else if (payload.group_ids != null && payload.group_ids !== '') {
    groupIds = [Number(payload.group_ids)].filter((n) => n > 0);
  }
  // unique
  groupIds = [...new Set(groupIds)];

  if (username.length < 2) return { ok: false, error: 'اسم المستخدم مطلوب (حرفان على الأقل).' };
  if (/\s/.test(username)) return { ok: false, error: 'اسم المستخدم لا يجب أن يحتوي على مسافات.' };
  if (!fullName) return { ok: false, error: 'الاسم الكامل مطلوب.' };
  if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return { ok: false, error: 'البريد الإلكتروني مطلوب وصالح (لاستعادة كلمة المرور).' };
  }
  if (!groupIds.length) return { ok: false, error: 'اختر مجموعة واحدة على الأقل للمستخدم.' };
  if (id > 0 && id === Number(currentUserId) && isActive === 0) {
    return { ok: false, error: 'لا يمكنك تعطيل حسابك الحالي.' };
  }

  if (id < 1 && !password) {
    return { ok: false, error: 'كلمة المرور مطلوبة للمستخدم الجديد.' };
  }
  if (password || passwordConfirm) {
    if (password !== passwordConfirm) return { ok: false, error: 'تأكيد كلمة المرور غير متطابق.' };
    if (password.length < 6) return { ok: false, error: 'كلمة المرور يجب أن تكون 6 أحرف على الأقل.' };
  }

  const dup = await safeQuery(
    `SELECT id FROM sys_user WHERE username = ? AND id <> ? LIMIT 1`,
    [username, id]
  );
  if (dup[0]) return { ok: false, error: 'اسم المستخدم مستخدم مسبقاً.' };

  const dupEmail = await safeQuery(
    `SELECT id FROM sys_user WHERE LOWER(TRIM(email)) = LOWER(?) AND id <> ? LIMIT 1`,
    [email, id]
  );
  if (dupEmail[0]) return { ok: false, error: 'البريد الإلكتروني مستخدم لحساب آخر.' };

  if (salesRepId) {
    const rep = await safeQuery(
      `SELECT id FROM crm_sales_rep WHERE id = ? AND is_active = 1 LIMIT 1`,
      [salesRepId]
    );
    if (!rep[0]) return { ok: false, error: 'المندوب المحدد غير موجود أو غير نشط.' };
    try {
      const other = await safeQuery(
        `SELECT id, username FROM sys_user WHERE sales_rep_id = ? AND id <> ? LIMIT 1`,
        [salesRepId, id]
      );
      if (other[0]) {
        return {
          ok: false,
          error: `هذا المندوب مرتبط بالفعل بالمستخدم «${other[0].username}».`,
        };
      }
    } catch {
      /* column optional */
    }
  }

  const allGroups = await safeQuery(`SELECT id FROM sys_group`);
  const validSet = new Set(allGroups.map((g) => Number(g.id)));
  groupIds = groupIds.filter((g) => validSet.has(g));
  if (!groupIds.length) return { ok: false, error: 'مجموعة غير صالحة.' };

  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    let userId = id;

    if (id < 1) {
      const hash = hashPassword(password);
      try {
        const [ins] = await conn.execute(
          `INSERT INTO sys_user (username, password_hash, full_name_ar, email, sales_rep_id, is_active)
           VALUES (?,?,?,?,?,?)`,
          [username, hash, fullName, email, salesRepId, isActive || 1]
        );
        userId = Number(ins.insertId);
      } catch {
        const [ins] = await conn.execute(
          `INSERT INTO sys_user (username, password_hash, full_name_ar, email, is_active)
           VALUES (?,?,?,?,?)`,
          [username, hash, fullName, email, isActive || 1]
        );
        userId = Number(ins.insertId);
      }
    } else {
      const [exists] = await conn.execute(`SELECT id FROM sys_user WHERE id = ? LIMIT 1`, [id]);
      if (!exists[0]) {
        await conn.rollback();
        return { ok: false, error: 'المستخدم غير موجود.' };
      }
      try {
        await conn.execute(
          `UPDATE sys_user SET username=?, full_name_ar=?, email=?, sales_rep_id=?, is_active=? WHERE id=?`,
          [username, fullName, email, salesRepId, isActive, id]
        );
      } catch {
        await conn.execute(
          `UPDATE sys_user SET username=?, full_name_ar=?, email=?, is_active=? WHERE id=?`,
          [username, fullName, email, isActive, id]
        );
      }
      if (password) {
        await conn.execute(`UPDATE sys_user SET password_hash = ? WHERE id = ?`, [
          hashPassword(password),
          id,
        ]);
      }
    }

    await conn.execute(`DELETE FROM sys_user_group WHERE user_id = ?`, [userId]);
    for (const gid of groupIds) {
      await conn.execute(`INSERT INTO sys_user_group (user_id, group_id) VALUES (?,?)`, [userId, gid]);
    }

    await conn.commit();
    return {
      ok: true,
      id: userId,
      message: id < 1 ? 'تم إضافة المستخدم.' : 'تم حفظ بيانات المستخدم.',
    };
  } catch (e) {
    await conn.rollback();
    console.error('saveUser', e.message);
    return { ok: false, error: 'تعذر حفظ المستخدم: ' + (e.message || '') };
  } finally {
    conn.release();
  }
}

module.exports = {
  listUsers,
  listGroups,
  listSalesReps,
  getUser,
  saveUser,
};
