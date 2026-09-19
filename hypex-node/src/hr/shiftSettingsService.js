'use strict';

const db = require('../db');

async function q(sql, params = []) {
  return db.query(sql, params);
}

async function safe(sql, params = []) {
  try {
    return await q(sql, params);
  } catch (e) {
    console.error('shift settings', e.message);
    return [];
  }
}

function formatTime(t) {
  if (t == null || t === '') return '';
  const s = String(t).trim();
  const m = s.match(/^(\d{1,2}):(\d{2})/);
  return m ? String(m[1]).padStart(2, '0') + ':' + m[2] : s.slice(0, 5);
}

function parseTime(raw, label) {
  const s = String(raw || '').trim();
  if (!s) throw new Error(label + ' مطلوب.');
  if (!/^\d{1,2}:\d{2}$/.test(s)) {
    throw new Error(label + ' بصيغة ساعة:دقيقة مثل 07:00.');
  }
  const [h, m] = s.split(':').map(Number);
  if (h < 0 || h > 23 || m < 0 || m > 59) throw new Error(label + ' غير صالح.');
  return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
}

function isHoliday(start, end) {
  return formatTime(start) === '00:00' && formatTime(end) === '00:00';
}

async function nextCode() {
  try {
    const a = await q(
      `SELECT COALESCE(MAX(CAST(shift_code AS UNSIGNED)), 0) AS m FROM hr_att_shift`
    );
    const b = await q(`SELECT COALESCE(MAX(id), 0) AS m FROM hr_att_shift`);
    return String(Math.max(Number(a[0]?.m || 0), Number(b[0]?.m || 0)) + 1);
  } catch {
    return '1';
  }
}

async function listShifts() {
  return safe(
    `SELECT id, shift_code, shift_name, start_time, end_time, is_active
     FROM hr_att_shift
     ORDER BY CAST(shift_code AS UNSIGNED) ASC, id ASC`
  );
}

async function getShift(id) {
  const n = Number(id);
  if (n < 1) return null;
  const rows = await safe(`SELECT * FROM hr_att_shift WHERE id = ? LIMIT 1`, [n]);
  return rows[0] || null;
}

async function nameTaken(name, excludeId = 0) {
  const n = String(name || '').trim();
  if (!n) return false;
  const rows = await safe(
    `SELECT id FROM hr_att_shift WHERE shift_name = ? AND id <> ? LIMIT 1`,
    [n, excludeId]
  );
  return !!rows[0];
}

async function usageCount(shiftId) {
  let c = 0;
  for (const sql of [
    `SELECT COUNT(*) AS c FROM hr_att_employee_default_shift WHERE shift_id = ?`,
    `SELECT COUNT(*) AS c FROM hr_att_employee_weekly_day WHERE shift_id = ?`,
  ]) {
    try {
      const rows = await q(sql, [shiftId]);
      c += Number(rows[0]?.c || 0);
    } catch {
      /* table may not exist */
    }
  }
  return c;
}

async function saveShift(payload) {
  const id = Number(payload.id || 0) || 0;
  const name = String(payload.shift_name || '').trim();
  let start;
  let end;
  try {
    start = parseTime(payload.start_time, 'وقت بداية الشفت');
    end = parseTime(payload.end_time, 'وقت نهاية الشفت');
  } catch (e) {
    return { ok: false, error: e.message };
  }
  const isActive =
    payload.is_active === '1' ||
    payload.is_active === 1 ||
    payload.is_active === true ||
    payload.is_active === 'on'
      ? 1
      : 0;

  if (!name) return { ok: false, error: 'اسم الشفت مطلوب.' };
  if (start === end && !isHoliday(start, end)) {
    return {
      ok: false,
      error: 'وقت البداية يجب أن يختلف عن النهاية (ما عدا العطل: 00:00 — 00:00).',
    };
  }
  if (await nameTaken(name, id)) {
    return { ok: false, error: 'اسم الشفت مستخدم لسجل آخر.' };
  }

  const startDb = start + ':00';
  const endDb = end + ':00';

  try {
    if (id > 0) {
      const cur = await getShift(id);
      if (!cur) return { ok: false, error: 'الشفت غير موجود.' };
      await q(
        `UPDATE hr_att_shift SET shift_name = ?, start_time = ?, end_time = ?, is_active = ? WHERE id = ?`,
        [name, startDb, endDb, isActive, id]
      );
      return { ok: true, id, message: 'تم حفظ تعديلات الشفت.' };
    }

    for (let attempt = 0; attempt < 5; attempt++) {
      const code = await nextCode();
      try {
        const r = await q(
          `INSERT INTO hr_att_shift (shift_code, shift_name, start_time, end_time, is_active)
           VALUES (?, ?, ?, ?, ?)`,
          [code, name, startDb, endDb, isActive || 1]
        );
        return {
          ok: true,
          id: Number(r.insertId || 0),
          message: 'تم إضافة الشفت برقم ' + code + '.',
        };
      } catch (e) {
        if (e.errno === 1062 && attempt < 4) continue;
        throw e;
      }
    }
    return { ok: false, error: 'تعذر توليد رقم شفت جديد.' };
  } catch (e) {
    return { ok: false, error: 'تعذر الحفظ: ' + (e.message || '') };
  }
}

async function deleteShift(id) {
  const n = Number(id);
  if (n < 1) return { ok: false, error: 'معرّف غير صالح.' };
  const used = await usageCount(n);
  if (used > 0) {
    return {
      ok: false,
      error: 'لا يمكن حذف الشفت: مستخدم في جداول دوام الموظفين (' + used + ').',
    };
  }
  try {
    await q(`DELETE FROM hr_att_shift WHERE id = ?`, [n]);
    return { ok: true, message: 'تم حذف الشفت.' };
  } catch (e) {
    if (e.errno === 1451) {
      return { ok: false, error: 'لا يمكن حذف الشفت لارتباطه بسجلات أخرى.' };
    }
    return { ok: false, error: e.message || 'تعذر الحذف.' };
  }
}

async function toggleActive(id, active) {
  const n = Number(id);
  if (n < 1) return { ok: false, error: 'معرّف غير صالح.' };
  const isActive = active ? 1 : 0;
  try {
    await q(`UPDATE hr_att_shift SET is_active = ? WHERE id = ?`, [isActive, n]);
    return {
      ok: true,
      message: isActive ? 'تم تفعيل الشفت.' : 'تم إيقاف الشفت.',
    };
  } catch (e) {
    return { ok: false, error: e.message || 'تعذر التحديث.' };
  }
}

module.exports = {
  formatTime,
  nextCode,
  listShifts,
  getShift,
  saveShift,
  deleteShift,
  toggleActive,
  isHoliday,
};
