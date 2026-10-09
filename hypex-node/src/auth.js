'use strict';

const bcrypt = require('bcryptjs');
const db = require('./db');
const permissionsVersion = require('./lib/permissionsVersion');

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

/** توسيع صلاحيات الإجراءات المرتبطة بشاشات ممنوحة (inherit_from). */
function expandInheritedActions(codes) {
  const set = new Set((codes || []).map(String).filter(Boolean));
  if (!set.size) return [];
  try {
    const permissionsNav = require('./system/permissionsNav');
    for (const actionItem of permissionsNav.actionItemsFlat()) {
      const actionCode = String(actionItem.code || '');
      if (!actionCode || set.has(actionCode)) continue;
      const parents = Array.isArray(actionItem.inherit_from) ? actionItem.inherit_from : [];
      if (parents.some((p) => set.has(String(p || '')))) {
        set.add(actionCode);
      }
    }
  } catch (e) {
    console.error('expandInheritedActions', e.message || e);
  }
  return [...set];
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
  return expandInheritedActions(rows.map((r) => r.code));
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
  const permsVer = await permissionsVersion.getPermissionsVersion();

  return {
    ok: true,
    user: {
      id: uid,
      username: row.username,
      full_name_ar: row.full_name_ar || row.username,
      is_admin: admin,
      permissions,
      permissions_loaded_at: Date.now(),
      permissions_version: permsVer,
    },
  };
}

function userCan(sessionUser, screenCode) {
  if (!sessionUser) return false;
  if (sessionUser.is_admin) return true;
  const perms = sessionUser.permissions || [];
  return perms.includes(screenCode);
}

/** إعادة تحميل صلاحيات المستخدم في الجلسة من قاعدة البيانات. */
async function refreshSessionPermissions(sessionUser) {
  if (!sessionUser || !sessionUser.id) return sessionUser;
  const uid = Number(sessionUser.id);
  const admin = await isSystemAdmin(uid);
  const permissions = await loadPermissions(uid, admin);
  const permsVer = await permissionsVersion.getPermissionsVersion();
  sessionUser.is_admin = admin;
  sessionUser.permissions = permissions;
  sessionUser.permissions_loaded_at = Date.now();
  sessionUser.permissions_version = permsVer;
  return sessionUser;
}

/**
 * يضمن تحديث صلاحيات الجلسة دورياً أو عند تغيّر إصدار الصلاحيات بعد حفظ المجموعة.
 */
async function ensureSessionPermissions(sessionUser, ttlSeconds = 60) {
  if (!sessionUser || !sessionUser.id) return sessionUser;
  const loadedAt = Number(sessionUser.permissions_loaded_at || 0);
  const ttlMs = Math.max(30, ttlSeconds) * 1000;
  const sessionVer = String(sessionUser.permissions_version || '');
  let globalVer = '0';
  try {
    globalVer = await permissionsVersion.getPermissionsVersion();
  } catch {
    globalVer = '0';
  }
  const versionStale = sessionVer === '' || sessionVer !== globalVer;
  if (
    Array.isArray(sessionUser.permissions) &&
    loadedAt > 0 &&
    !versionStale &&
    Date.now() - loadedAt < ttlMs
  ) {
    return sessionUser;
  }
  return refreshSessionPermissions(sessionUser);
}

function requireAuth(req, res, next) {
  if (req.session && req.session.user) {
    return ensureSessionPermissions(req.session.user)
      .then((user) => {
        req.session.user = user;
        next();
      })
      .catch((err) => next(err));
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
  loadPermissions,
  isSystemAdmin,
  refreshSessionPermissions,
  ensureSessionPermissions,
  expandInheritedActions,
};
