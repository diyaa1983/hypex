'use strict';

const db = require('../db');

async function q(sql, params = []) {
  return db.query(sql, params);
}

async function safe(sql, params = []) {
  try {
    return await q(sql, params);
  } catch (e) {
    console.error('departure types', e.message);
    return [];
  }
}

async function nextCode() {
  try {
    const rows = await q(
      `SELECT COALESCE(MAX(CAST(type_code AS UNSIGNED)), 0) AS m FROM hr_departure_type
       WHERE type_code REGEXP '^[0-9]+$'`
    );
    return String(Number(rows[0]?.m || 0) + 1);
  } catch {
    return '1';
  }
}

async function listTypes() {
  return safe(
    `SELECT id, type_code, name_ar, is_active, created_at
     FROM hr_departure_type
     ORDER BY CAST(type_code AS UNSIGNED) ASC, id ASC`
  );
}

async function getType(id) {
  const n = Number(id);
  if (n < 1) return null;
  const rows = await safe(`SELECT * FROM hr_departure_type WHERE id = ? LIMIT 1`, [n]);
  return rows[0] || null;
}

async function usageCount(typeId) {
  try {
    const rows = await q(
      `SELECT COUNT(*) AS c FROM hr_employee_departure WHERE departure_type_id = ?`,
      [typeId]
    );
    return Number(rows[0]?.c || 0);
  } catch {
    return 0;
  }
}

async function saveType(payload) {
  const id = Number(payload.id || 0) || 0;
  const name = String(payload.name_ar || '').trim();
  const isActive =
    payload.is_active === '1' ||
    payload.is_active === 1 ||
    payload.is_active === true ||
    payload.is_active === 'on'
      ? 1
      : 0;

  if (!name) return { ok: false, error: 'اسم نوع المغادرة مطلوب.' };

  try {
    let code;
    if (id > 0) {
      const cur = await getType(id);
      if (!cur) return { ok: false, error: 'نوع المغادرة غير موجود.' };
      code = String(cur.type_code || '').trim();
      if (!code) return { ok: false, error: 'نوع المغادرة غير موجود.' };
    } else {
      code = await nextCode();
    }

    const codeDup = await safe(
      `SELECT id FROM hr_departure_type WHERE type_code = ? AND id <> ? LIMIT 1`,
      [code, id]
    );
    if (codeDup[0]) return { ok: false, error: 'رقم نوع المغادرة مستخدم مسبقاً.' };

    const nameDup = await safe(
      `SELECT id FROM hr_departure_type WHERE name_ar = ? AND id <> ? LIMIT 1`,
      [name, id]
    );
    if (nameDup[0]) return { ok: false, error: 'اسم نوع المغادرة مستخدم مسبقاً.' };

    if (id > 0) {
      await q(
        `UPDATE hr_departure_type SET type_code = ?, name_ar = ?, is_active = ? WHERE id = ?`,
        [code, name, isActive, id]
      );
      return { ok: true, id, message: 'تم حفظ تعديلات نوع المغادرة.' };
    }

    for (let attempt = 0; attempt < 5; attempt++) {
      const c = attempt === 0 ? code : await nextCode();
      try {
        const r = await q(
          `INSERT INTO hr_departure_type (type_code, name_ar, is_active) VALUES (?, ?, ?)`,
          [c, name, isActive || 1]
        );
        return {
          ok: true,
          id: Number(r.insertId || 0),
          message: 'تم إضافة نوع المغادرة برقم ' + c + '.',
        };
      } catch (e) {
        if (e.errno === 1062 && attempt < 4) continue;
        throw e;
      }
    }
    return { ok: false, error: 'تعذر توليد رقم نوع مغادرة جديد.' };
  } catch (e) {
    return { ok: false, error: 'تعذر الحفظ: ' + (e.message || '') };
  }
}

async function deleteType(id) {
  const n = Number(id);
  if (n < 1) return { ok: false, error: 'معرّف غير صالح.' };
  const used = await usageCount(n);
  if (used > 0) {
    return {
      ok: false,
      error: 'لا يمكن حذف نوع مغادرة مستخدم في سندات مغادرة.',
    };
  }
  try {
    await q(`DELETE FROM hr_departure_type WHERE id = ?`, [n]);
    return { ok: true, message: 'تم حذف نوع المغادرة.' };
  } catch (e) {
    if (e.errno === 1451) {
      return { ok: false, error: 'لا يمكن الحذف لارتباطه بسجلات أخرى.' };
    }
    return { ok: false, error: e.message || 'تعذر الحذف.' };
  }
}

async function toggleActive(id, active) {
  const n = Number(id);
  if (n < 1) return { ok: false, error: 'معرّف غير صالح.' };
  const isActive = active ? 1 : 0;
  try {
    await q(`UPDATE hr_departure_type SET is_active = ? WHERE id = ?`, [isActive, n]);
    return {
      ok: true,
      message: isActive ? 'تم تفعيل نوع المغادرة.' : 'تم إيقاف نوع المغادرة.',
    };
  } catch (e) {
    return { ok: false, error: e.message || 'تعذر التحديث.' };
  }
}

module.exports = {
  nextCode,
  listTypes,
  getType,
  saveType,
  deleteType,
  toggleActive,
};
