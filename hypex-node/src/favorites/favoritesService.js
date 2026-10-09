'use strict';

const db = require('../db');
const auth = require('../auth');
const { resolveScreen } = require('../lib/screenMap');
const { permCodeForRoute } = require('../lib/routePermissions');

const BLOCKED = new Set(['dashboard', 'menu_hub', 'favorites_empty', 'login', 'logout']);

async function ensureSchema() {
  await db.query(
    `CREATE TABLE IF NOT EXISTS sys_user_favorite (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id INT UNSIGNED NOT NULL,
      screen_code VARCHAR(64) NOT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_sys_user_favorite (user_id, screen_code),
      KEY ix_sys_user_favorite_user (user_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
  );
}

async function codesForUser(userId) {
  const uid = Number(userId || 0);
  if (uid < 1) return [];
  await ensureSchema();
  try {
    const rows = await db.query(
      `SELECT screen_code FROM sys_user_favorite
       WHERE user_id = ? ORDER BY sort_order ASC, id ASC`,
      [uid]
    );
    return rows.map((r) => String(r.screen_code || '')).filter(Boolean);
  } catch {
    return [];
  }
}

function isBlocked(code) {
  return BLOCKED.has(String(code || '').trim());
}

function canFavorite(user, screenCode) {
  const code = String(screenCode || '').trim();
  if (!code || isBlocked(code)) return false;
  const sc = resolveScreen(code);
  if (!sc) return false;
  if (user && user.is_admin) return true;
  const perm = permCodeForRoute(sc.r) || sc.r;
  return perm !== '' && auth.userCan(user, perm);
}

async function toggle(user, screenCode) {
  const uid = Number(user && user.id);
  const code = String(screenCode || '').trim();
  if (uid < 1 || !code) {
    return { ok: false, favorited: false, message: 'بيانات غير صالحة.' };
  }
  if (isBlocked(code)) {
    return { ok: false, favorited: false, message: 'لا يمكن تفضيل هذه الشاشة.' };
  }
  if (!resolveScreen(code)) {
    return { ok: false, favorited: false, message: 'الشاشة غير معروفة.' };
  }
  if (!canFavorite(user, code)) {
    return { ok: false, favorited: false, message: 'لا تملك صلاحية على هذه الشاشة.' };
  }

  await ensureSchema();
  try {
    const existing = await db.query(
      `SELECT id FROM sys_user_favorite WHERE user_id = ? AND screen_code = ? LIMIT 1`,
      [uid, code]
    );
    if (existing[0]) {
      await db.query(`DELETE FROM sys_user_favorite WHERE id = ?`, [Number(existing[0].id)]);
      return { ok: true, favorited: false, message: 'أُزيلت من المفضلة.' };
    }
    const maxRows = await db.query(
      `SELECT COALESCE(MAX(sort_order), 0) AS m FROM sys_user_favorite WHERE user_id = ?`,
      [uid]
    );
    const next = Number(maxRows[0]?.m || 0) + 1;
    await db.query(
      `INSERT INTO sys_user_favorite (user_id, screen_code, sort_order) VALUES (?, ?, ?)`,
      [uid, code, next]
    );
    return { ok: true, favorited: true, message: 'أُضيفت إلى المفضلة.' };
  } catch (e) {
    console.error('favorites.toggle', e.message || e);
    return { ok: false, favorited: false, message: 'تعذر حفظ المفضلة.' };
  }
}

module.exports = {
  ensureSchema,
  codesForUser,
  canFavorite,
  isBlocked,
  toggle,
};
