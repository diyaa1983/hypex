'use strict';

const db = require('../db');

async function q(sql, params = []) {
  return db.query(sql, params);
}

async function safeCount(table, employeeId) {
  try {
    const rows = await q(`SELECT COUNT(*) AS c FROM ${table} WHERE employee_id = ?`, [employeeId]);
    return Number(rows[0]?.c || 0);
  } catch {
    return 0;
  }
}

function buildFullName(first, father, grandfather, family) {
  return [first, father, grandfather, family]
    .map((p) => String(p || '').trim())
    .filter(Boolean)
    .join(' ');
}

function emptyRow() {
  return {
    id: 0,
    emp_code: '',
    name_ar: '',
    name_first: '',
    name_father: '',
    name_grandfather: '',
    name_family: '',
    national_id: '',
    birth_date: '',
    gender: '',
    is_married: 0,
    nationality_id: null,
    phone: '',
    email: '',
    address_ar: '',
    address_city: '',
    address_district: '',
    job_title: '',
    job_title_id: null,
    department: '',
    department_id: null,
    hire_date: '',
    resignation_date: '',
    base_salary: 0,
    social_security_no: '',
    subject_to_social_security: 0,
    subject_to_income_tax: 0,
    notes: '',
    is_active: 1,
    is_resigned_posted: 0,
  };
}

async function listEmployees({ q: search = '', activeOnly = true } = {}) {
  const where = ['1=1'];
  const params = [];
  if (activeOnly) {
    where.push(
      `(e.is_active = 1 AND (e.resignation_date IS NULL OR e.resignation_date < '1000-01-01'))`
    );
  }
  if (search) {
    const like = `%${search}%`;
    where.push(
      `(e.name_ar LIKE ? OR IFNULL(e.emp_code,'') LIKE ? OR IFNULL(e.phone,'') LIKE ? OR IFNULL(e.national_id,'') LIKE ? OR IFNULL(e.department,'') LIKE ?)`
    );
    params.push(like, like, like, like, like);
  }
  return q(
    `SELECT e.id, e.emp_code, e.name_ar, e.phone, e.department, e.job_title, e.hire_date,
            e.resignation_date, e.is_active, e.is_resigned_posted
     FROM hr_employee e
     WHERE ${where.join(' AND ')}
     ORDER BY CAST(IFNULL(e.emp_code,'0') AS UNSIGNED) ASC, e.name_ar ASC
     LIMIT 500`,
    params
  );
}

async function getEmployee(id) {
  const n = Number(id);
  if (!Number.isFinite(n) || n < 1) return null;
  const rows = await q(`SELECT * FROM hr_employee WHERE id = ? LIMIT 1`, [n]);
  if (!rows[0]) return null;
  const row = rows[0];
  // normalize dates to ISO strings
  for (const k of ['hire_date', 'resignation_date', 'birth_date']) {
    if (row[k] && typeof row[k] === 'object' && row[k].toISOString) {
      row[k] = row[k].toISOString().slice(0, 10);
    } else if (row[k]) {
      row[k] = String(row[k]).slice(0, 10);
    } else {
      row[k] = '';
    }
  }
  return row;
}

async function listDepartments() {
  try {
    return await q(
      `SELECT id, name_ar FROM hr_department WHERE is_active = 1 OR is_active IS NULL ORDER BY name_ar`
    );
  } catch {
    return [];
  }
}

async function listJobTitles() {
  try {
    return await q(
      `SELECT id, name_ar, department_id FROM hr_job_title WHERE is_active = 1 OR is_active IS NULL ORDER BY name_ar`
    );
  } catch {
    return [];
  }
}

async function listNationalities() {
  try {
    return await q(`SELECT id, name_ar FROM hr_nationality ORDER BY name_ar`);
  } catch {
    return [];
  }
}

async function nextEmpCode() {
  try {
    const rows = await q(
      `SELECT COALESCE(MAX(CAST(emp_code AS UNSIGNED)), 0) AS m FROM hr_employee WHERE emp_code REGEXP '^[0-9]+$'`
    );
    return String(Number(rows[0]?.m || 0) + 1);
  } catch {
    const rows = await q(`SELECT COALESCE(MAX(id), 0) AS m FROM hr_employee`);
    return String(Number(rows[0]?.m || 0) + 1);
  }
}

async function assertEditable(id) {
  const row = await getEmployee(id);
  if (!row) throw new Error('الموظف غير موجود.');
  if (Number(row.is_resigned_posted) === 1) {
    throw new Error('بطاقة موظف مستقيل مرحّلة — لا يمكن التعديل إلا بعد فك الترحيل.');
  }
  return row;
}

async function deleteCheck(id) {
  const n = Number(id);
  if (!Number.isFinite(n) || n < 1) return { can_delete: false, message: 'معرّف غير صالح.' };
  const row = await getEmployee(n);
  if (!row) return { can_delete: false, message: 'الموظف غير موجود.' };
  const blocks = [];
  for (const [table, label] of [
    ['hr_salary', 'رواتب'],
    ['hr_social_security', 'ضمان اجتماعي'],
    ['hr_employee_advance', 'سلف'],
    ['hr_employee_salary_line', 'إعدادات راتب'],
    ['hr_employee_monthly_payroll_line', 'علاوات شهرية'],
  ]) {
    const c = await safeCount(table, n);
    if (c > 0) blocks.push(`${label} (${c})`);
  }
  if (blocks.length) {
    return {
      can_delete: false,
      message: 'لا يمكن حذف الموظف: يوجد عليه حركات — ' + blocks.join('، ') + '.',
    };
  }
  return { can_delete: true, message: '' };
}

async function resolveDeptJob(payload) {
  let deptId = Number(payload.department_id || 0) || 0;
  let jobTitleId = Number(payload.job_title_id || 0) || 0;
  let dept = '';
  let job = '';
  if (deptId > 0) {
    const rows = await q(`SELECT name_ar FROM hr_department WHERE id = ? LIMIT 1`, [deptId]);
    dept = String(rows[0]?.name_ar || '');
    if (!dept) deptId = 0;
  }
  if (jobTitleId > 0) {
    try {
      const rows = await q(
        `SELECT name_ar, department_id FROM hr_job_title WHERE id = ? LIMIT 1`,
        [jobTitleId]
      );
      if (rows[0]) {
        job = String(rows[0].name_ar || '');
        if (deptId < 1 && rows[0].department_id) {
          deptId = Number(rows[0].department_id);
          const d2 = await q(`SELECT name_ar FROM hr_department WHERE id = ? LIMIT 1`, [deptId]);
          dept = String(d2[0]?.name_ar || '');
        }
      } else {
        jobTitleId = 0;
      }
    } catch {
      jobTitleId = 0;
    }
  }
  return { deptId, jobTitleId, dept, job };
}

function nullIfEmpty(s) {
  const t = String(s || '').trim();
  return t === '' ? null : t;
}

function dateOrNull(s) {
  const t = String(s || '').trim();
  if (!t) return null;
  const { parseDateToIso } = require('../lib/html');
  const iso = parseDateToIso(t, null);
  return iso || null;
}

async function saveEmployee(payload) {
  const id = Number(payload.id || 0) || 0;
  if (id > 0) await assertEditable(id);

  const nameFirst = String(payload.name_first || '').trim();
  const nameFather = String(payload.name_father || '').trim();
  const nameGrandfather = String(payload.name_grandfather || '').trim();
  const nameFamily = String(payload.name_family || '').trim();
  const name = buildFullName(nameFirst, nameFather, nameGrandfather, nameFamily);
  let code = String(payload.emp_code || '').trim();
  const nid = String(payload.national_id || '').trim();
  let gender = String(payload.gender || '').trim();
  if (gender && gender !== 'male' && gender !== 'female') gender = '';
  let nationalityId = Number(payload.nationality_id || 0) || 0;
  if (nationalityId > 0) {
    try {
      const n = await q(`SELECT id FROM hr_nationality WHERE id = ? LIMIT 1`, [nationalityId]);
      if (!n[0]) nationalityId = 0;
    } catch {
      nationalityId = 0;
    }
  }
  const phone = String(payload.phone || '').trim();
  const email = String(payload.email || '').trim();
  const addressAr = String(payload.address_ar || '').trim();
  const addressCity = String(payload.address_city || '').trim();
  const addressDistrict = String(payload.address_district || '').trim();
  const { deptId, jobTitleId, dept, job } = await resolveDeptJob(payload);
  const hireRaw = String(payload.hire_date || '').trim();
  const hire = dateOrNull(hireRaw) || '';
  const baseSalary = Number(payload.base_salary || 0) || 0;
  const ssn = String(payload.social_security_no || '').trim();
  const subjectToSs =
    payload.subject_to_social_security === '1' ||
    payload.subject_to_social_security === 1 ||
    payload.subject_to_social_security === true
      ? 1
      : 0;
  const subjectToIt =
    payload.subject_to_income_tax === '1' ||
    payload.subject_to_income_tax === 1 ||
    payload.subject_to_income_tax === true
      ? 1
      : 0;
  const isMarried =
    payload.is_married === '1' || payload.is_married === 1 || payload.is_married === true ? 1 : 0;
  const notes = String(payload.notes || '').trim();
  const resignDateRaw = String(payload.resignation_date || '').trim();
  const resignDate = dateOrNull(resignDateRaw) || '';
  const isResigned =
    payload.is_resigned === '1' ||
    payload.is_resigned === 1 ||
    payload.is_resigned === true ||
    !!resignDate;

  if (!nameFirst) return { ok: false, error: 'الاسم الأول مطلوب.' };
  if (!name) return { ok: false, error: 'اسم الموظف مطلوب.' };
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return { ok: false, error: 'صيغة البريد الإلكتروني غير صحيحة.' };
  }
  if (baseSalary < 0) return { ok: false, error: 'الراتب الأساسي يجب ألا يكون سالباً.' };
  if (!hire) {
    return { ok: false, error: 'تاريخ التعيين مطلوب (يوم-شهر-سنة).' };
  }

  let isActive = 1;
  let resignDateVal = null;
  if (isResigned || resignDate) {
    if (!resignDate) {
      return { ok: false, error: 'أدخل تاريخ الاستقالة (يوم-شهر-سنة).' };
    }
    isActive = 0;
    resignDateVal = resignDate;
  }

  if (!code) {
    code = await nextEmpCode();
  } else {
    if (!/^\d+$/.test(code)) {
      return { ok: false, error: 'الرقم الوظيفي يجب أن يحتوي أرقاماً فقط.' };
    }
    const dup = await q(
      `SELECT id FROM hr_employee WHERE emp_code = ? AND id <> ? LIMIT 1`,
      [code, id]
    );
    if (dup[0]) return { ok: false, error: 'الرقم الوظيفي مستخدم لموظف آخر.' };
  }

  const params = [
    code,
    name,
    nameFirst,
    nameFather,
    nameGrandfather,
    nameFamily,
    nullIfEmpty(nid),
    dateOrNull(payload.birth_date),
    nullIfEmpty(gender),
    isMarried,
    nationalityId > 0 ? nationalityId : null,
    nullIfEmpty(phone),
    nullIfEmpty(email),
    nullIfEmpty(addressAr),
    nullIfEmpty(addressCity),
    nullIfEmpty(addressDistrict),
    nullIfEmpty(job),
    jobTitleId > 0 ? jobTitleId : null,
    nullIfEmpty(dept),
    deptId > 0 ? deptId : null,
    hire,
    resignDateVal,
    baseSalary,
    nullIfEmpty(ssn),
    subjectToSs,
    subjectToIt,
    nullIfEmpty(notes),
    isActive,
  ];

  try {
    if (id > 0) {
      await q(
        `UPDATE hr_employee SET emp_code=?, name_ar=?, name_first=?, name_father=?, name_grandfather=?, name_family=?,
         national_id=?, birth_date=?, gender=?, is_married=?, nationality_id=?,
         phone=?, email=?, address_ar=?, address_city=?, address_district=?,
         job_title=?, job_title_id=?, department=?, department_id=?,
         hire_date=?, resignation_date=?, base_salary=?, social_security_no=?,
         subject_to_social_security=?, subject_to_income_tax=?, notes=?, is_active=?
         WHERE id=?`,
        [...params, id]
      );
      return { ok: true, id, message: 'تم حفظ تعديلات الموظف.' };
    }
    const r = await q(
      `INSERT INTO hr_employee (emp_code, name_ar, name_first, name_father, name_grandfather, name_family,
        national_id, birth_date, gender, is_married, nationality_id, phone, email,
        address_ar, address_city, address_district,
        job_title, job_title_id, department, department_id, hire_date, resignation_date, base_salary,
        social_security_no, subject_to_social_security, subject_to_income_tax, notes, is_active)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
      params
    );
    const newId = Number(r.insertId || 0);
    return { ok: true, id: newId, message: 'تم إضافة الموظف برقم ' + code + '.' };
  } catch (e) {
    if (e.errno === 1062 || String(e.message || '').includes('Duplicate')) {
      return { ok: false, error: 'الرقم الوظيفي مستخدم مسبقاً.' };
    }
    return { ok: false, error: 'تعذر الحفظ: ' + (e.message || '') };
  }
}

async function deleteEmployee(id) {
  const n = Number(id);
  try {
    await assertEditable(n);
  } catch (e) {
    return { ok: false, error: e.message };
  }
  const chk = await deleteCheck(n);
  if (!chk.can_delete) return { ok: false, error: chk.message };
  try {
    await q(`DELETE FROM hr_employee WHERE id = ?`, [n]);
    return { ok: true, message: 'تم حذف الموظف.' };
  } catch (e) {
    return { ok: false, error: 'تعذر الحذف: ' + (e.message || '') };
  }
}

async function postResignation(id, resignDate) {
  const n = Number(id);
  if (n < 1) return { ok: false, error: 'اختر موظفاً أولاً.' };
  const row = await getEmployee(n);
  if (!row) return { ok: false, error: 'الموظف غير موجود.' };
  if (Number(row.is_resigned_posted) === 1) {
    return { ok: false, error: 'البطاقة مرحّلة مسبقاً.' };
  }
  let d = String(resignDate || row.resignation_date || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) {
    return { ok: false, error: 'أدخل تاريخ الاستقالة قبل الترحيل.' };
  }
  await q(
    `UPDATE hr_employee SET resignation_date = ?, is_active = 0, is_resigned_posted = 1 WHERE id = ?`,
    [d, n]
  );
  return { ok: true, message: 'تم ترحيل استقالة الموظف.' };
}

async function unpostResignation(id) {
  const n = Number(id);
  if (n < 1) return { ok: false, error: 'اختر موظفاً أولاً.' };
  const row = await getEmployee(n);
  if (!row) return { ok: false, error: 'الموظف غير موجود.' };
  if (Number(row.is_resigned_posted) !== 1) {
    return { ok: false, error: 'لا يوجد ترحيل استقالة لفكّه.' };
  }
  await q(
    `UPDATE hr_employee SET is_resigned_posted = 0, is_active = 1 WHERE id = ?`,
    [n]
  );
  return { ok: true, message: 'تم فك ترحيل الاستقالة.' };
}

module.exports = {
  emptyRow,
  listEmployees,
  getEmployee,
  listDepartments,
  listJobTitles,
  listNationalities,
  saveEmployee,
  deleteEmployee,
  postResignation,
  unpostResignation,
  buildFullName,
};
