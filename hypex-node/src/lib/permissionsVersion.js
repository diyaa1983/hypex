'use strict';

const db = require('../db');

const META_KEY = 'sys_permissions_version';
let cachedVersion = null;
let cachedAt = 0;
const CACHE_MS = 2000;

async function ensureMetaTable() {
  await db.query(
    `CREATE TABLE IF NOT EXISTS acc_system_meta (
      meta_key VARCHAR(40) NOT NULL PRIMARY KEY,
      meta_value VARCHAR(200) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
  );
}

async function getPermissionsVersion() {
  const now = Date.now();
  if (cachedVersion != null && now - cachedAt < CACHE_MS) {
    return cachedVersion;
  }
  try {
    await ensureMetaTable();
    const rows = await db.query(
      `SELECT meta_value FROM acc_system_meta WHERE meta_key = ? LIMIT 1`,
      [META_KEY]
    );
    const v = rows[0]?.meta_value;
    cachedVersion = v != null && String(v) !== '' ? String(v) : '0';
    cachedAt = now;
    return cachedVersion;
  } catch {
    return cachedVersion != null ? cachedVersion : '0';
  }
}

async function bumpPermissionsVersion() {
  const v = String(Date.now());
  try {
    await ensureMetaTable();
    await db.query(
      `INSERT INTO acc_system_meta (meta_key, meta_value) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)`,
      [META_KEY, v]
    );
    cachedVersion = v;
    cachedAt = Date.now();
  } catch (e) {
    console.error('bumpPermissionsVersion', e.message || e);
  }
  return v;
}

module.exports = {
  getPermissionsVersion,
  bumpPermissionsVersion,
};
