'use strict';

const bcrypt = require('bcryptjs');
const db = require('./db');

/**
 * يتحقق من كلمة مرور PHP password_hash (bcrypt $2y$ / $2a$ / $2b$).
 */
function verifyPassword(plain, hash) {
  if (!plain || !hash || typeof hash !== 'string') return false;
  let h = hash;
  if (h.startsWith('$2y$')) {
    h = '$2a$' + h.slice(4);
  }
  try {
    return bcrypt.compareSync(plain, h);
  } catch {
    return false;
  }
}

async function isSystemAdmin(userId) {
  const rows = await db.query(
    `SELECT 1 AS ok
     FROM sys_user_group ug
     INNER JOIN sys_group g ON g.id = ug.group_id AND g.code = ?
     WHERE ug.user_id = ?
     LIMIT 1`,
    ['ADMINS', userId]
  );
  return rows.length > 0;
}

async function loadPermissions(userId, isAdmin) {
  if (isAdmin) {
    const all = await db.query('SELECT code FROM sys_screen ORDER BY sort_order, id');
    return all.map((r) => r.code);
  }
  const rows = await db.query(
    `SELECT DISTINCT s.code
     FROM sys_user_group ug
     INNER JOIN sys_group_permission gp ON gp.group_id = ug.group_id AND gp.allowed = 1
     INNER JOIN sys_screen s ON s.id = gp.screen_id
     WHERE ug.user_id = ?`,
    [userId]
  );
  return rows.map((r) => r.code);
}

async function attemptLogin(username, password) {
  const name = String(username || '').trim();
  if (!name || !password) {
    return { ok: false, error: 'أدخل اسم المستخدم وكلمة المرور.' };
  }

  const rows = await db.query(
    `SELECT id, username, password_hash, full_name_ar, is_active
     FROM sys_user WHERE username = ? LIMIT 1`,
    [name]
  );
  const row = rows[0];
  if (!row || !Number(row.is_active)) {
    return { ok: false, error: 'بيانات الدخول غير صحيحة.' };
  }
  if (!verifyPassword(password, row.password_hash)) {
    return { ok: false, error: 'بيانات الدخول غير صحيحة.' };
  }

  const uid = Number(row.id);
  const admin = await isSystemAdmin(uid);
  const permissions = await loadPermissions(uid, admin);

  return {
    ok: true,
    user: {
      id: uid,
      username: row.username,
      full_name_ar: row.full_name_ar || row.username,
      is_admin: admin,
      permissions,
    },
  };
}

function userCan(sessionUser, screenCode) {
  if (!sessionUser) return false;
  if (sessionUser.is_admin) return true;
  const perms = sessionUser.permissions || [];
  return perms.includes(screenCode);
}

function requireAuth(req, res, next) {
  if (req.session && req.session.user) {
    return next();
  }
  if (req.path.startsWith('/api/')) {
    return res.status(401).json({ ok: false, error: 'غير مسجّل الدخول' });
  }
  return res.redirect('/login');
}

module.exports = {
  attemptLogin,
  userCan,
  requireAuth,
  verifyPassword,
};
