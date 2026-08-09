'use strict';

const db = require('../db');
const { parseDateToIso, todayIso } = require('../lib/html');

async function q(sql, params = []) {
  return db.query(sql, params);
}

async function safe(sql, params = []) {
  try {
    return await q(sql, params);
  } catch (e) {
    console.error('departures', e.message);
    return [];
  }
}

function formatTime(t) {
  if (t == null || t === '') return '';
  const s = String(t).trim();
  const m = s.match(/^(\d{1,2}):(\d{2})/);
  return m ? String(m[1]).padStart(2, '0') + ':' + m[2] : s.slice(0, 5);
}

function normalizeTime(raw, label) {
  const s = String(raw || '')
    .trim()
    .replace(/[.\u066B]/g, ':');
  if (!/^\d{1,2}:\d{2}$/.test(s)) {
    throw new Error(label + ' بصيغة ساعة:دقيقة (مثل 09:30).');
  }
  const [h, m] = s.split(':').map(Number);
  if (h < 0 || h > 23 || m < 0 || m > 59) {
    throw new Error(label + ' خارج النطاق (00:00 — 23:59).');
  }
  return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
}

function timeMinutes(hhmm) {
  const t = formatTime(hhmm);
  if (!t) return 0;
  const [h, m] = t.split(':').map(Number);
  return h * 60 + m;
}

function durationLabel(timeFrom, timeTo) {
  const mins = Math.max(0, timeMinutes(timeTo) - timeMinutes(timeFrom));
  return String(Math.floor(mins / 60)).padStart(2, '0') + ':' + String(mins % 60).padStart(2, '0');
}

function durationMinutes(timeFrom, timeTo) {
  return Math.max(0, timeMinutes(timeTo) - timeMinutes(timeFrom));
}

async function nextVoucherNo() {
  try {
    const a = await q(
      `SELECT COALESCE(MAX(CAST(voucher_no AS UNSIGNED)), 0) AS m FROM hr_employee_departure`
    );
    const b = await q(`SELECT COALESCE(MAX(id), 0) AS m FROM hr_employee_departure`);
    return String(Math.max(Number(a[0]?.m || 0), Number(b[0]?.m || 0)) + 1);
  } catch {
    return '1';
  }
}

async function listEmployees() {
  return safe(
    `SELECT id, emp_code, name_ar FROM hr_employee
     WHERE is_active = 1
       AND (resignation_date IS NULL OR resignation_date = 0 OR resignation_date < '1000-01-01')
     ORDER BY name_ar LIMIT 800`
  );
}

async function listTypes(activeOnly = true) {
  const where = activeOnly ? 'WHERE is_active = 1' : '';
  return safe(
    `SELECT id, type_code, name_ar, is_active FROM hr_departure_type ${where}
     ORDER BY CAST(type_code AS UNSIGNED) ASC, id ASC`
  );
}

async function listDepartments() {
  return safe(
    `SELECT id, name_ar FROM hr_department
     WHERE COALESCE(is_active, 1) = 1 ORDER BY name_ar, id`
  );
}

/**
 * @param {{from?:string,to?:string,employeeId?:number,limit?:number}} opts
 */
async function listDepartures(opts = {}) {
  const where = ['1=1'];
  const params = [];
  const from = String(opts.from || '').trim();
  const to = String(opts.to || '').trim();
  const employeeId = Number(opts.employeeId || 0) || 0;
  const limit = Math.max(1, Math.min(2000, Number(opts.limit || 300)));

  if (from) {
    where.push('d.departure_date >= ?');
    params.push(from);
  }
  if (to) {
    where.push('d.departure_date <= ?');
    params.push(to);
  }
  if (employeeId > 0) {
    where.push('d.employee_id = ?');
    params.push(employeeId);
  }

  return safe(
    `SELECT d.*,
            e.emp_code, e.name_ar AS employee_name,
            t.type_code, t.name_ar AS type_name
     FROM hr_employee_departure d
     LEFT JOIN hr_employee e ON e.id = d.employee_id
     LEFT JOIN hr_departure_type t ON t.id = d.departure_type_id
     WHERE ${where.join(' AND ')}
     ORDER BY d.departure_date DESC, CAST(d.voucher_no AS UNSIGNED) DESC, d.id DESC
     LIMIT ${limit}`,
    params
  );
}

async function getDeparture(id) {
  const n = Number(id);
  if (n < 1) return null;
  const rows = await safe(
    `SELECT d.*,
            e.emp_code, e.name_ar AS employee_name,
            t.type_code, t.name_ar AS type_name
     FROM hr_employee_departure d
     LEFT JOIN hr_employee e ON e.id = d.employee_id
     LEFT JOIN hr_departure_type t ON t.id = d.departure_type_id
     WHERE d.id = ? LIMIT 1`,
    [n]
  );
  return rows[0] || null;
}

async function lookupByVoucher(fragment) {
  const qv = String(fragment || '').trim();
  if (!qv) return null;
  const rows = await safe(
    `SELECT id FROM hr_employee_departure
     WHERE voucher_no = ? OR voucher_no LIKE ?
     ORDER BY CAST(voucher_no AS UNSIGNED) DESC, id DESC LIMIT 1`,
    [qv, '%' + qv + '%']
  );
  if (!rows[0]) return null;
  return getDeparture(rows[0].id);
}

function parseRow(payload) {
  const employeeId = Number(payload.employee_id || 0);
  if (employeeId < 1) throw new Error('اختر الموظف.');

  const typeId = Number(payload.departure_type_id || 0);
  if (typeId < 1) throw new Error('اختر نوع المغادرة.');

  const departureDate = parseDateToIso(payload.departure_date || todayIso());
  if (!/^\d{4}-\d{2}-\d{2}$/.test(departureDate)) {
    throw new Error('تاريخ المغادرة غير صالح.');
  }

  const timeFrom = normalizeTime(payload.time_from, 'وقت بداية المغادرة');
  const timeTo = normalizeTime(payload.time_to, 'وقت نهاية المغادرة');
  if (timeMinutes(timeTo) <= timeMinutes(timeFrom)) {
    throw new Error('وقت نهاية المغادرة يجب أن يكون بعد وقت البداية.');
  }

  const notes = String(payload.notes || '').trim();

  return {
    employee_id: employeeId,
    departure_type_id: typeId,
    departure_date: departureDate,
    time_from: timeFrom + ':00',
    time_to: timeTo + ':00',
    notes: notes || null,
  };
}

async function ensureTypeActive(typeId) {
  const rows = await safe(
    `SELECT id FROM hr_departure_type WHERE id = ? AND is_active = 1 LIMIT 1`,
    [typeId]
  );
  if (!rows[0]) throw new Error('نوع المغادرة غير موجود أو غير نشط.');
}

async function saveDeparture(payload, userId) {
  const id = Number(payload.id || 0) || 0;
  let parsed;
  try {
    parsed = parseRow(payload);
    await ensureTypeActive(parsed.departure_type_id);
  } catch (e) {
    return { ok: false, error: e.message };
  }

  try {
    if (id > 0) {
      const cur = await getDeparture(id);
      if (!cur) return { ok: false, error: 'سند المغادرة غير موجود.' };
      if (Number(cur.is_posted) === 1) {
        return { ok: false, error: 'لا يمكن تعديل مغادرة مرحّلة — فك الترحيل أولاً.' };
      }
      await q(
        `UPDATE hr_employee_departure
         SET employee_id = ?, departure_type_id = ?, departure_date = ?,
             time_from = ?, time_to = ?, notes = ?
         WHERE id = ?`,
        [
          parsed.employee_id,
          parsed.departure_type_id,
          parsed.departure_date,
          parsed.time_from,
          parsed.time_to,
          parsed.notes,
          id,
        ]
      );
      return { ok: true, id, message: 'تم حفظ سند المغادرة.' };
    }

    const uid = Number(userId || 0) || null;
    for (let attempt = 0; attempt < 5; attempt++) {
      const voucherNo = await nextVoucherNo();
      try {
        const r = await q(
          `INSERT INTO hr_employee_departure
             (voucher_no, employee_id, departure_type_id, departure_date, time_from, time_to, notes, created_by)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
          [
            voucherNo,
            parsed.employee_id,
            parsed.departure_type_id,
            parsed.departure_date,
            parsed.time_from,
            parsed.time_to,
            parsed.notes,
            uid,
          ]
        );
        return {
          ok: true,
          id: Number(r.insertId || 0),
          message: 'تم إضافة سند المغادرة رقم ' + voucherNo + '.',
        };
      } catch (e) {
        if (e.errno === 1062 && attempt < 4) continue;
        throw e;
      }
    }
    return { ok: false, error: 'تعذر توليد رقم سند مغادرة جديد.' };
  } catch (e) {
    return { ok: false, error: 'تعذر الحفظ: ' + (e.message || '') };
  }
}

async function deleteDeparture(id) {
  const n = Number(id);
  if (n < 1) return { ok: false, error: 'معرّف غير صالح.' };
  const cur = await getDeparture(n);
  if (!cur) return { ok: false, error: 'السند غير موجود.' };
  if (Number(cur.is_posted) === 1) {
    return { ok: false, error: 'لا يمكن حذف مغادرة مرحّلة — فك الترحيل أولاً.' };
  }
  try {
    await q(`DELETE FROM hr_employee_departure WHERE id = ?`, [n]);
    return { ok: true, message: 'تم حذف سند المغادرة.' };
  } catch (e) {
    return { ok: false, error: e.message || 'تعذر الحذف.' };
  }
}

async function postDeparture(id, userId) {
  const n = Number(id);
  if (n < 1) return { ok: false, error: 'سند المغادرة غير موجود.' };
  const cur = await getDeparture(n);
  if (!cur) return { ok: false, error: 'سند المغادرة غير موجود.' };
  if (Number(cur.is_posted) === 1) {
    return { ok: false, error: 'المغادرة مرحّلة مسبقاً.' };
  }
  try {
    const uid = Number(userId || 0) || null;
    await q(
      `UPDATE hr_employee_departure SET is_posted = 1, posted_at = NOW(), posted_by = ? WHERE id = ?`,
      [uid, n]
    );
    return { ok: true, message: 'تم ترحيل المغادرة — ستظهر في كشف حركة دوام الموظفين.' };
  } catch (e) {
    return { ok: false, error: e.message || 'تعذر الترحيل.' };
  }
}

async function unpostDeparture(id) {
  const n = Number(id);
  if (n < 1) return { ok: false, error: 'سند المغادرة غير موجود.' };
  const cur = await getDeparture(n);
  if (!cur) return { ok: false, error: 'سند المغادرة غير موجود.' };
  if (Number(cur.is_posted) !== 1) {
    return { ok: false, error: 'المغادرة غير مرحّلة.' };
  }
  try {
    await q(
      `UPDATE hr_employee_departure SET is_posted = 0, posted_at = NULL, posted_by = NULL WHERE id = ?`,
      [n]
    );
    return { ok: true, message: 'تم فك ترحيل المغادرة.' };
  } catch (e) {
    return { ok: false, error: e.message || 'تعذر فك الترحيل.' };
  }
}

/**
 * تقرير مفصّل — مجموعة حسب القسم
 */
async function reportRows({ from, to, departmentId = 0, typeId = 0 } = {}) {
  const dateFrom = from || todayIso();
  const dateTo = to || todayIso();
  const where = ['d.departure_date >= ?', 'd.departure_date <= ?'];
  const params = [dateFrom, dateTo];
  const dept = Number(departmentId || 0) || 0;
  const type = Number(typeId || 0) || 0;

  if (dept > 0) {
    where.push('e.department_id = ?');
    params.push(dept);
  }
  if (type > 0) {
    where.push('d.departure_type_id = ?');
    params.push(type);
  }

  const rows = await safe(
    `SELECT d.id, d.voucher_no, d.departure_date, d.time_from, d.time_to,
            d.is_posted, d.notes,
            e.emp_code, e.name_ar AS emp_name, e.department_id,
            COALESCE(dept.name_ar, NULLIF(TRIM(e.department), ''), '—') AS dept_name,
            t.name_ar AS type_name
     FROM hr_employee_departure d
     INNER JOIN hr_employee e ON e.id = d.employee_id
     INNER JOIN hr_departure_type t ON t.id = d.departure_type_id
     LEFT JOIN hr_department dept ON dept.id = e.department_id
     WHERE ${where.join(' AND ')}
     ORDER BY dept_name ASC, e.name_ar ASC, d.departure_date ASC, d.time_from ASC, d.id ASC`,
    params
  );

  const depts = new Map();
  let grandMinutes = 0;

  for (const row of rows) {
    const deptName = String(row.dept_name || '—');
    const deptId = Number(row.department_id || 0);
    const key = deptId + '|' + deptName;
    if (!depts.has(key)) {
      depts.set(key, {
        dept_id: deptId,
        dept_name: deptName,
        rows: [],
        total_minutes: 0,
      });
    }
    const g = depts.get(key);
    const mins = durationMinutes(row.time_from, row.time_to);
    g.rows.push({
      voucher_no: String(row.voucher_no || ''),
      emp_code: String(row.emp_code || ''),
      emp_name: String(row.emp_name || ''),
      type_name: String(row.type_name || ''),
      departure_date: String(row.departure_date || ''),
      time_from: formatTime(row.time_from),
      time_to: formatTime(row.time_to),
      duration_label: durationLabel(row.time_from, row.time_to),
      is_posted: Number(row.is_posted) === 1 ? 1 : 0,
      notes: String(row.notes || ''),
    });
    g.total_minutes += mins;
    grandMinutes += mins;
  }

  const departments = [...depts.values()].map((d) => ({
    ...d,
    row_count: d.rows.length,
    total_duration_label: minsToLabel(d.total_minutes),
  }));

  return {
    departments,
    row_count: rows.length,
    grand_total_minutes: grandMinutes,
    grand_total_duration_label: minsToLabel(grandMinutes),
    from: dateFrom,
    to: dateTo,
  };
}

function minsToLabel(mins) {
  const m = Math.max(0, Number(mins) || 0);
  return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
}

module.exports = {
  formatTime,
  nextVoucherNo,
  listEmployees,
  listTypes,
  listDepartments,
  listDepartures,
  getDeparture,
  lookupByVoucher,
  saveDeparture,
  deleteDeparture,
  postDeparture,
  unpostDeparture,
  reportRows,
  durationLabel,
};
