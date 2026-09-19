'use strict';

const db = require('../db');

/**
 * جيران المستند + الأول والأخير بالـ id
 * @param {string} table
 * @param {number} id
 * @param {{ whereSql?: string, params?: any[] }} [opts]
 */
async function neighbors(table, id, opts = {}) {
  const n = Number(id) || 0;
  const where = opts.whereSql ? ` WHERE (${opts.whereSql})` : '';
  const andWhere = opts.whereSql ? ` AND (${opts.whereSql})` : '';
  const params = Array.isArray(opts.params) ? opts.params : [];

  const firstRows = await db.query(
    `SELECT id FROM \`${table}\`${where} ORDER BY id ASC LIMIT 1`,
    params
  );
  const lastRows = await db.query(
    `SELECT id FROM \`${table}\`${where} ORDER BY id DESC LIMIT 1`,
    params
  );
  const first_id = firstRows[0] ? Number(firstRows[0].id) : 0;
  const last_id = lastRows[0] ? Number(lastRows[0].id) : 0;

  if (n < 1) {
    // مستند جديد: السهم السابق يفتح آخر مستند، والأول/الآخر يعملان من الأزرار
    return { prev_id: last_id, next_id: 0, first_id, last_id };
  }

  const prev = await db.query(
    `SELECT id FROM \`${table}\` WHERE id < ?${andWhere} ORDER BY id DESC LIMIT 1`,
    [n, ...params]
  );
  const next = await db.query(
    `SELECT id FROM \`${table}\` WHERE id > ?${andWhere} ORDER BY id ASC LIMIT 1`,
    [n, ...params]
  );

  return {
    prev_id: prev[0] ? Number(prev[0].id) : 0,
    next_id: next[0] ? Number(next[0].id) : 0,
    first_id,
    last_id,
  };
}

/**
 * بحث عن id برقم المستند (تطابق كامل → بادئة → يحتوي)
 * @param {string} table
 * @param {string} noCol
 * @param {string} no
 * @param {{ whereSql?: string, params?: any[] }} [opts]
 */
async function findIdByNo(table, noCol, no, opts = {}) {
  const s = String(no || '').trim();
  if (!s) return 0;
  const where = opts.whereSql ? ` AND (${opts.whereSql})` : '';
  const params = Array.isArray(opts.params) ? opts.params : [];

  const exact = await db.query(
    `SELECT id FROM \`${table}\` WHERE \`${noCol}\` = ?${where} LIMIT 1`,
    [s, ...params]
  );
  if (exact[0]) return Number(exact[0].id);

  const byPrefix = await db.query(
    `SELECT id FROM \`${table}\` WHERE \`${noCol}\` LIKE ?${where} ORDER BY id DESC LIMIT 1`,
    [`${s}%`, ...params]
  );
  if (byPrefix[0]) return Number(byPrefix[0].id);

  const like = await db.query(
    `SELECT id FROM \`${table}\` WHERE \`${noCol}\` LIKE ?${where} ORDER BY id DESC LIMIT 1`,
    [`%${s}%`, ...params]
  );
  return like[0] ? Number(like[0].id) : 0;
}

module.exports = { neighbors, findIdByNo };
