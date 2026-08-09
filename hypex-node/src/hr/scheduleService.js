'use strict';

const db = require('../db');

const DAY_NAMES = {
  0: 'السبت',
  1: 'الأحد',
  2: 'الاثنين',
  3: 'الثلاثاء',
  4: 'الأربعاء',
  5: 'الخميس',
  6: 'الجمعة',
};

async function q(sql, params = []) {
  return db.query(sql, params);
}

async function safe(sql, params = []) {
  try {
    return await q(sql, params);
  } catch (e) {
    console.error('schedule', e.message);
    return [];
  }
}

/** JS Date: 0=Sun … 6=Sat → index 0=Sat … 6=Fri */
function dayIndex(isoDate) {
  const d = new Date(String(isoDate).slice(0, 10) + 'T12:00:00');
  if (Number.isNaN(d.getTime())) return -1;
  return (d.getDay() + 1) % 7;
}

function addDays(iso, days) {
  const d = new Date(String(iso).slice(0, 10) + 'T12:00:00');
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

function weekSaturday(isoDate) {
  const idx = dayIndex(isoDate);
  if (idx < 0) throw new Error('تاريخ غير صالح.');
  if (idx === 0) return String(isoDate).slice(0, 10);
  return addDays(isoDate, -idx);
}

function weekFriday(satIso) {
  return addDays(weekSaturday(satIso), 6);
}

function formatTime(t) {
  if (!t) return '';
  const s = String(t);
  const m = s.match(/^(\d{1,2}):(\d{2})/);
  return m ? m[1].padStart(2, '0') + ':' + m[2] : s.slice(0, 5);
}

async function listShifts() {
  const rows = await safe(
    `SELECT id, shift_code, shift_name, start_time, end_time
     FROM hr_att_shift WHERE is_active = 1 ORDER BY shift_code, id`
  );
  return rows.map((r) => {
    const start = formatTime(r.start_time);
    const end = formatTime(r.end_time);
    const isHoliday = start === '00:00' && end === '00:00';
    let label;
    if (isHoliday) {
      label = (r.shift_name || 'شفت') + ' (00:00-00:00)';
    } else {
      label =
        (r.shift_code || '') +
        ' — ' +
        (r.shift_name || 'شفت') +
        (start && end ? ` (${start}-${end})` : '');
    }
    return { id: Number(r.id), label, code: r.shift_code, name: r.shift_name };
  });
}

async function listEmployees() {
  return safe(
    `SELECT id, emp_code, name_ar FROM hr_employee
     WHERE is_active = 1
     ORDER BY CAST(IFNULL(emp_code,'0') AS UNSIGNED), name_ar
     LIMIT 1000`
  );
}

async function getEmployee(id) {
  const n = Number(id);
  if (n < 1) return null;
  const rows = await safe(`SELECT id, emp_code, name_ar FROM hr_employee WHERE id = ? LIMIT 1`, [n]);
  return rows[0] || null;
}

async function loadSchedule(employeeId) {
  const eid = Number(employeeId) || 0;
  const empty = { employee_id: eid, default_shift_id: 0, weekly_periods: [] };
  if (eid < 1) return empty;

  const def = await safe(
    `SELECT shift_id FROM hr_att_employee_default_shift WHERE employee_id = ? LIMIT 1`,
    [eid]
  );
  const defaultShiftId = Number(def[0]?.shift_id || 0);

  const weeks = await safe(
    `SELECT id, date_from, date_to FROM hr_att_employee_weekly
     WHERE employee_id = ? ORDER BY date_from ASC, id ASC`,
    [eid]
  );

  const periods = [];
  for (const w of weeks) {
    const days = { 0: 0, 1: 0, 2: 0, 3: 0, 4: 0, 5: 0, 6: 0 };
    const dayRows = await safe(
      `SELECT day_index, shift_id FROM hr_att_employee_weekly_day WHERE weekly_id = ?`,
      [w.id]
    );
    for (const d of dayRows) {
      const idx = Number(d.day_index);
      if (idx >= 0 && idx <= 6) days[idx] = Number(d.shift_id || 0);
    }
    periods.push({
      id: Number(w.id),
      date_from: String(w.date_from || '').slice(0, 10),
      date_to: String(w.date_to || '').slice(0, 10),
      days,
    });
  }

  return {
    employee_id: eid,
    default_shift_id: defaultShiftId,
    weekly_periods: periods,
  };
}

function normalizeWeekRange(dateFrom, dateTo) {
  const from = String(dateFrom || '').trim().slice(0, 10);
  const to = String(dateTo || '').trim().slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to)) {
    throw new Error('أدخل تاريخ البداية والنهاية بصيغة صحيحة.');
  }
  if (from > to) throw new Error('تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.');
  if (dayIndex(from) !== 0) throw new Error('تاريخ البداية يجب أن يكون يوم سبت.');
  if (dayIndex(to) !== 6) throw new Error('تاريخ النهاية يجب أن يكون يوم جمعة.');
  const expected = weekFriday(from);
  if (to !== expected) {
    throw new Error(
      'مدة الفترة يجب أن تكون 7 أيام (من السبت إلى الجمعة). النهاية المتوقعة: ' + expected
    );
  }
  return { date_from: from, date_to: to };
}

async function assertNoOverlap(employeeId, dateFrom, dateTo, excludeId = 0) {
  const rows = await safe(
    `SELECT id, date_from, date_to FROM hr_att_employee_weekly
     WHERE employee_id = ? AND id <> ?
       AND date_from <= ? AND date_to >= ?
     LIMIT 1`,
    [employeeId, excludeId, dateTo, dateFrom]
  );
  if (rows[0]) {
    throw new Error(
      'تتداخل هذه الفترة مع أسبوع آخر: ' +
        String(rows[0].date_from).slice(0, 10) +
        ' — ' +
        String(rows[0].date_to).slice(0, 10)
    );
  }
}

async function assertShift(shiftId) {
  if (shiftId < 1) return;
  const rows = await safe(
    `SELECT id FROM hr_att_shift WHERE id = ? AND is_active = 1 LIMIT 1`,
    [shiftId]
  );
  if (!rows[0]) throw new Error('الشفت المختار غير موجود أو غير مفعّل.');
}

async function saveDefault(employeeId, shiftId) {
  const eid = Number(employeeId);
  const sid = Number(shiftId) || 0;
  if (eid < 1) return { ok: false, error: 'اختر الموظف.' };
  const emp = await getEmployee(eid);
  if (!emp) return { ok: false, error: 'الموظف غير موجود.' };
  try {
    await assertShift(sid);
    if (sid < 1) {
      await q(`DELETE FROM hr_att_employee_default_shift WHERE employee_id = ?`, [eid]);
    } else {
      await q(
        `INSERT INTO hr_att_employee_default_shift (employee_id, shift_id) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id)`,
        [eid, sid]
      );
    }
    return { ok: true, message: 'تم حفظ الشفت الافتراضي.' };
  } catch (e) {
    return { ok: false, error: e.message || 'تعذر الحفظ.' };
  }
}

async function saveWeekly(employeeId, weeklyId, dateFrom, dateTo, dayShifts) {
  const eid = Number(employeeId);
  const wid = Number(weeklyId) || 0;
  if (eid < 1) return { ok: false, error: 'اختر الموظف.' };
  const emp = await getEmployee(eid);
  if (!emp) return { ok: false, error: 'الموظف غير موجود.' };

  try {
    const range = normalizeWeekRange(dateFrom, dateTo);
    await assertNoOverlap(eid, range.date_from, range.date_to, wid);

    const parsed = {};
    let hasAny = false;
    for (let i = 0; i <= 6; i++) {
      const sid = Number(dayShifts[i] || dayShifts[String(i)] || 0) || 0;
      if (sid > 0) {
        await assertShift(sid);
        hasAny = true;
        parsed[i] = sid;
      } else {
        parsed[i] = null;
      }
    }

    if (!hasAny) {
      const def = await safe(
        `SELECT shift_id FROM hr_att_employee_default_shift WHERE employee_id = ? LIMIT 1`,
        [eid]
      );
      if (Number(def[0]?.shift_id || 0) < 1) {
        throw new Error(
          'عيّن شفتاً ليوم واحد على الأقل، أو عيّن الشفت الافتراضي أولاً.'
        );
      }
    }

    let newId = wid;
    if (wid > 0) {
      const chk = await safe(
        `SELECT id FROM hr_att_employee_weekly WHERE id = ? AND employee_id = ? LIMIT 1`,
        [wid, eid]
      );
      if (!chk[0]) throw new Error('الفترة الأسبوعية غير موجودة.');
      await q(`UPDATE hr_att_employee_weekly SET date_from = ?, date_to = ? WHERE id = ?`, [
        range.date_from,
        range.date_to,
        wid,
      ]);
      await q(`DELETE FROM hr_att_employee_weekly_day WHERE weekly_id = ?`, [wid]);
    } else {
      const r = await q(
        `INSERT INTO hr_att_employee_weekly (employee_id, date_from, date_to) VALUES (?, ?, ?)`,
        [eid, range.date_from, range.date_to]
      );
      newId = Number(r.insertId || 0);
    }

    for (let i = 0; i <= 6; i++) {
      if (parsed[i] == null) continue;
      await q(
        `INSERT INTO hr_att_employee_weekly_day (weekly_id, day_index, shift_id) VALUES (?, ?, ?)`,
        [newId, i, parsed[i]]
      );
    }

    return {
      ok: true,
      id: newId,
      message: wid > 0 ? 'تم حفظ الفترة الأسبوعية.' : 'تم إضافة فترة أسبوعية جديدة.',
    };
  } catch (e) {
    return { ok: false, error: e.message || 'تعذر الحفظ.' };
  }
}

async function deleteWeekly(employeeId, weeklyId) {
  const eid = Number(employeeId);
  const wid = Number(weeklyId);
  if (wid < 1) return { ok: false, error: 'معرّف الفترة غير صالح.' };
  try {
    const r = await q(
      `DELETE FROM hr_att_employee_weekly WHERE id = ? AND employee_id = ?`,
      [wid, eid]
    );
    if (!r.affectedRows) return { ok: false, error: 'الفترة غير موجودة.' };
    return { ok: true, message: 'تم حذف الفترة الأسبوعية.' };
  } catch (e) {
    return { ok: false, error: e.message || 'تعذر الحذف.' };
  }
}

function suggestNextWeek(periods) {
  if (periods && periods.length) {
    const last = periods[periods.length - 1];
    const lastTo = String(last.date_to || '').slice(0, 10);
    if (/^\d{4}-\d{2}-\d{2}$/.test(lastTo)) {
      const start = weekSaturday(addDays(lastTo, 1));
      return { date_from: start, date_to: weekFriday(start) };
    }
  }
  const start = weekSaturday(new Date().toISOString().slice(0, 10));
  return { date_from: start, date_to: weekFriday(start) };
}

function dayDatesForWeek(dateFrom) {
  const sat = weekSaturday(dateFrom);
  const out = [];
  for (let i = 0; i <= 6; i++) {
    out.push({ index: i, name: DAY_NAMES[i], date: addDays(sat, i) });
  }
  return out;
}

module.exports = {
  DAY_NAMES,
  dayIndex,
  weekSaturday,
  weekFriday,
  listShifts,
  listEmployees,
  getEmployee,
  loadSchedule,
  saveDefault,
  saveWeekly,
  deleteWeekly,
  suggestNextWeek,
  dayDatesForWeek,
};
