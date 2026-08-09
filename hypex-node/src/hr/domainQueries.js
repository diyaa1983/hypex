'use strict';

const db = require('../db');
const { todayIso } = require('../lib/html');

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('hr query', e.message);
    return [];
  }
}

function dateRange(from, to) {
  return { from: from || monthStart(), to: to || todayIso() };
}

async function dashboardKpis() {
  const active = await safeQuery(
    `SELECT COUNT(*) AS c FROM hr_employee
     WHERE is_active = 1 AND (resignation_date IS NULL OR resignation_date = 0)`
  );
  const resigned = await safeQuery(
    `SELECT COUNT(*) AS c FROM hr_employee
     WHERE resignation_date IS NOT NULL AND resignation_date > '1000-01-01'`
  );
  const depts = await safeQuery(`SELECT COUNT(*) AS c FROM hr_department WHERE is_active = 1`);
  const payroll = await safeQuery(
    `SELECT COALESCE(SUM(COALESCE(base_salary,0) + COALESCE(allowances,0)),0) AS s
     FROM hr_employee WHERE is_active = 1`
  );
  return {
    active: Number(active[0]?.c || 0),
    resigned: Number(resigned[0]?.c || 0),
    depts: Number(depts[0]?.c || 0),
    payroll: Number(payroll[0]?.s || 0),
  };
}

async function listEmployees({ q = '', activeOnly = true, resignedOnly = false } = {}) {
  const where = ['1=1'];
  const params = [];
  if (resignedOnly) {
    where.push(`e.resignation_date IS NOT NULL AND e.resignation_date > '1000-01-01'`);
  } else if (activeOnly) {
    where.push('e.is_active = 1');
  }
  if (q) {
    const like = `%${q}%`;
    where.push(
      `(e.name_ar LIKE ? OR IFNULL(e.emp_code,'') LIKE ? OR IFNULL(e.phone,'') LIKE ? OR IFNULL(e.national_id,'') LIKE ? OR IFNULL(e.department,'') LIKE ?)`
    );
    params.push(like, like, like, like, like);
  }
  return safeQuery(
    `SELECT e.id, e.emp_code, e.name_ar, e.phone, e.department, e.job_title, e.hire_date,
            e.resignation_date, e.base_salary, e.is_active
     FROM hr_employee e
     WHERE ${where.join(' AND ')}
     ORDER BY e.name_ar ASC
     LIMIT 300`,
    params
  );
}

async function reportEmployeesBy(field) {
  if (field === 'nationality') {
    return safeQuery(
      `SELECT COALESCE(n.name_ar, '—') AS label, COUNT(*) AS cnt
       FROM hr_employee e
       LEFT JOIN hr_nationality n ON n.id = e.nationality_id
       GROUP BY COALESCE(n.name_ar, '—')
       ORDER BY cnt DESC
       LIMIT 100`
    );
  }
  return safeQuery(
    `SELECT COALESCE(d.name_ar, NULLIF(TRIM(e.department), ''), '— بدون قسم —') AS label,
            COUNT(*) AS cnt
     FROM hr_employee e
     LEFT JOIN hr_department d ON d.id = e.department_id
     GROUP BY COALESCE(d.name_ar, NULLIF(TRIM(e.department), ''), '— بدون قسم —')
     ORDER BY cnt DESC
     LIMIT 100`
  );
}

function isResignedRow(row) {
  if (Number(row.is_active) === 0) return true;
  if (Number(row.is_resigned_posted) === 1) return true;
  const d = String(row.resignation_date || '').trim();
  return d !== '' && d !== '0000-00-00' && d > '1000-01-01';
}

/**
 * تقرير الموظفين حسب القسم — مطابق منطق PHP.
 * @param {{ status?: string, departmentId?: number }} opts
 */
async function reportEmployeesByDepartment({ status = 'all', departmentId = 0 } = {}) {
  const st = ['all', 'active', 'resigned'].includes(status) ? status : 'all';
  const deptId = Number(departmentId) || 0;
  const params = [];
  let where = '1=1';

  if (st === 'active') {
    where += ` AND e.is_active = 1
               AND COALESCE(e.is_resigned_posted, 0) = 0
               AND (e.resignation_date IS NULL OR e.resignation_date = '' OR e.resignation_date <= '1000-01-01')`;
  } else if (st === 'resigned') {
    where += ` AND (e.is_active = 0
               OR COALESCE(e.is_resigned_posted, 0) = 1
               OR (e.resignation_date IS NOT NULL AND e.resignation_date > '1000-01-01'))`;
  }

  if (deptId > 0) {
    where += ' AND e.department_id = ?';
    params.push(deptId);
  }

  const raw = await safeQuery(
    `SELECT e.id, e.emp_code, e.name_ar, e.hire_date, e.base_salary, e.allowances,
            e.is_active, e.resignation_date, e.is_resigned_posted, e.department_id,
            COALESCE(d.name_ar, NULLIF(TRIM(e.department), ''), '— بدون قسم —') AS dept_name,
            COALESCE(d.id, 0) AS dept_id_sort,
            COALESCE(jt.name_ar, NULLIF(TRIM(e.job_title), ''), '—') AS job_title_name
     FROM hr_employee e
     LEFT JOIN hr_department d ON d.id = e.department_id
     LEFT JOIN hr_job_title jt ON jt.id = e.job_title_id
     WHERE ${where}
     ORDER BY dept_name ASC,
              CASE WHEN e.emp_code REGEXP '^[0-9]+$' THEN CAST(e.emp_code AS UNSIGNED) ELSE 999999999 END ASC,
              e.emp_code ASC, e.id ASC`,
    params
  );

  const groups = new Map();
  for (const row of raw) {
    const key = String(row.dept_name || '— بدون قسم —');
    if (!groups.has(key)) {
      groups.set(key, {
        dept_id: Number(row.dept_id_sort || 0),
        dept_name: key,
        rows: [],
        employee_count: 0,
        total_salary: 0,
      });
    }
    const g = groups.get(key);
    const salary = Number(row.base_salary || 0) + Number(row.allowances || 0);
    g.rows.push({
      emp_code: row.emp_code || '',
      name_ar: row.name_ar || '',
      job_title_name: row.job_title_name || '—',
      hire_date: row.hire_date || '',
      salary,
      status_label: isResignedRow(row) ? 'مستقيل' : 'على رأس العمل',
    });
    g.employee_count += 1;
    g.total_salary += salary;
  }

  const departments = [];
  const grand = { employee_count: 0, total_salary: 0 };
  for (const g of groups.values()) {
    let seq = 1;
    for (const r of g.rows) r.seq = seq++;
    g.total_salary = Math.round(g.total_salary * 1000) / 1000;
    departments.push(g);
    grand.employee_count += g.employee_count;
    grand.total_salary += g.total_salary;
  }
  grand.total_salary = Math.round(grand.total_salary * 1000) / 1000;

  return { departments, grand, status: st, departmentId: deptId };
}

/**
 * تقرير الموظفين حسب الجنسية — مطابق منطق PHP.
 */
async function reportEmployeesByNationality({ status = 'all', nationalityId = 0 } = {}) {
  const st = ['all', 'active', 'resigned'].includes(status) ? status : 'all';
  const natId = Number(nationalityId) || 0;
  const params = [];
  let where = '1=1';

  if (st === 'active') {
    where += ` AND e.is_active = 1
               AND COALESCE(e.is_resigned_posted, 0) = 0
               AND (e.resignation_date IS NULL OR e.resignation_date = '' OR e.resignation_date <= '1000-01-01')`;
  } else if (st === 'resigned') {
    where += ` AND (e.is_active = 0
               OR COALESCE(e.is_resigned_posted, 0) = 1
               OR (e.resignation_date IS NOT NULL AND e.resignation_date > '1000-01-01'))`;
  }

  if (natId > 0) {
    where += ' AND e.nationality_id = ?';
    params.push(natId);
  }

  const raw = await safeQuery(
    `SELECT e.id, e.emp_code, e.name_ar, e.hire_date, e.base_salary, e.allowances,
            e.is_active, e.resignation_date, e.is_resigned_posted, e.nationality_id,
            COALESCE(n.name_ar, '— بدون جنسية —') AS nat_name,
            COALESCE(n.id, 0) AS nat_id_sort,
            COALESCE(jt.name_ar, NULLIF(TRIM(e.job_title), ''), '—') AS job_title_name
     FROM hr_employee e
     LEFT JOIN hr_nationality n ON n.id = e.nationality_id
     LEFT JOIN hr_job_title jt ON jt.id = e.job_title_id
     WHERE ${where}
     ORDER BY nat_name ASC,
              CASE WHEN e.emp_code REGEXP '^[0-9]+$' THEN CAST(e.emp_code AS UNSIGNED) ELSE 999999999 END ASC,
              e.emp_code ASC, e.id ASC`,
    params
  );

  const groups = new Map();
  for (const row of raw) {
    const key = String(row.nat_name || '— بدون جنسية —');
    if (!groups.has(key)) {
      groups.set(key, {
        nat_id: Number(row.nat_id_sort || 0),
        nat_name: key,
        rows: [],
        employee_count: 0,
        total_salary: 0,
      });
    }
    const g = groups.get(key);
    const salary = Number(row.base_salary || 0) + Number(row.allowances || 0);
    g.rows.push({
      emp_code: row.emp_code || '',
      name_ar: row.name_ar || '',
      job_title_name: row.job_title_name || '—',
      hire_date: row.hire_date || '',
      salary,
      status_label: isResignedRow(row) ? 'مستقيل' : 'على رأس العمل',
    });
    g.employee_count += 1;
    g.total_salary += salary;
  }

  const nationalities = [];
  const grand = { employee_count: 0, total_salary: 0 };
  for (const g of groups.values()) {
    let seq = 1;
    for (const r of g.rows) r.seq = seq++;
    g.total_salary = Math.round(g.total_salary * 1000) / 1000;
    nationalities.push(g);
    grand.employee_count += g.employee_count;
    grand.total_salary += g.total_salary;
  }
  grand.total_salary = Math.round(grand.total_salary * 1000) / 1000;

  return { nationalities, grand, status: st, nationalityId: natId };
}

async function listDepartments() {
  return safeQuery(
    `SELECT id, dept_code, name_ar, is_active FROM hr_department ORDER BY name_ar LIMIT 200`
  );
}

async function listJobTitles() {
  return safeQuery(
    `SELECT id, title_code, name_ar, is_active FROM hr_job_title ORDER BY name_ar LIMIT 200`
  );
}

async function listNationalities() {
  return safeQuery(`SELECT id, nat_code AS code, name_ar, is_active FROM hr_nationality ORDER BY name_ar LIMIT 200`);
}

async function listBanks() {
  return safeQuery(`SELECT id, bank_code, name_ar, is_active FROM hr_salary_bank ORDER BY name_ar LIMIT 200`);
}

async function listPayrollComponents() {
  return safeQuery(`SELECT * FROM hr_payroll_component ORDER BY id LIMIT 200`);
}

async function listLeaveTypes() {
  return safeQuery(`SELECT * FROM hr_leave_type ORDER BY id LIMIT 100`);
}

async function listDepartureTypes() {
  return safeQuery(`SELECT * FROM hr_departure_type ORDER BY id LIMIT 100`);
}

async function listLeaves({ from, to } = {}) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT l.id, l.voucher_no, l.leave_date, l.date_from, l.date_to, l.days_count, l.is_posted,
            e.name_ar AS employee_name, e.emp_code, t.name_ar AS leave_type
     FROM hr_employee_leave l
     LEFT JOIN hr_employee e ON e.id = l.employee_id
     LEFT JOIN hr_leave_type t ON t.id = l.leave_type_id
     WHERE COALESCE(l.date_from, l.leave_date) BETWEEN ? AND ?
     ORDER BY l.id DESC LIMIT 200`,
    [r.from, r.to]
  );
}

async function listLeaveBalances() {
  return safeQuery(
    `SELECT b.id, b.opening_balance, b.entitled_balance, b.taken_days,
            (COALESCE(b.opening_balance,0) + COALESCE(b.entitled_balance,0) - COALESCE(b.taken_days,0)) AS remaining,
            e.name_ar AS employee_name, e.emp_code, t.name_ar AS leave_type
     FROM hr_employee_leave_balance b
     LEFT JOIN hr_employee e ON e.id = b.employee_id
     LEFT JOIN hr_leave_type t ON t.id = b.leave_type_id
     ORDER BY e.name_ar LIMIT 400`
  );
}

async function listDepartures({ from, to } = {}) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT d.*, e.name_ar AS employee_name, e.emp_code
     FROM hr_employee_departure d
     LEFT JOIN hr_employee e ON e.id = d.employee_id
     WHERE d.departure_date BETWEEN ? AND ?
     ORDER BY d.id DESC LIMIT 200`,
    [r.from, r.to]
  );
}

async function listAdvances({ q = '' } = {}) {
  const params = [];
  let extra = '';
  if (q) {
    extra = ' AND (e.name_ar LIKE ? OR e.emp_code LIKE ?)';
    params.push(`%${q}%`, `%${q}%`);
  }
  return safeQuery(
    `SELECT a.id, a.advance_code, a.total_amount AS amount, a.start_date AS advance_date,
            a.status, a.is_posted, e.name_ar AS employee_name, e.emp_code
     FROM hr_employee_advance a
     LEFT JOIN hr_employee e ON e.id = a.employee_id
     WHERE 1=1 ${extra}
     ORDER BY a.id DESC LIMIT 150`,
    params
  );
}

async function listSalaries() {
  return safeQuery(
    `SELECT s.id, s.pay_year, s.pay_month, s.base_salary, s.net_salary, s.is_posted,
            e.name_ar AS employee_name, e.emp_code,
            CONCAT(s.pay_year, '-', LPAD(s.pay_month, 2, '0')) AS salary_month
     FROM hr_salary s
     LEFT JOIN hr_employee e ON e.id = s.employee_id
     ORDER BY s.pay_year DESC, s.pay_month DESC, s.id DESC LIMIT 150`
  );
}

async function listOvertime({ from, to } = {}) {
  // الجدول شهري (pay_year/pay_month) وليس يومي
  return safeQuery(
    `SELECT o.id, o.pay_year, o.pay_month, o.overtime_hours AS hours, o.overtime_amount,
            e.name_ar AS employee_name, e.emp_code,
            CONCAT(o.pay_year, '-', LPAD(o.pay_month, 2, '0'), '-01') AS ot_date
     FROM hr_employee_overtime o
     LEFT JOIN hr_employee e ON e.id = o.employee_id
     ORDER BY o.pay_year DESC, o.pay_month DESC, o.id DESC LIMIT 200`
  );
}

async function listPunches({ from, to } = {}) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT p.id, p.punch_time, p.punch_type, p.sensor_id AS device_sn,
            e.name_ar AS employee_name, e.emp_code
     FROM hr_att_punch p
     LEFT JOIN hr_employee e ON e.id = p.employee_id
     WHERE DATE(p.punch_time) BETWEEN ? AND ?
     ORDER BY p.punch_time DESC LIMIT 300`,
    [r.from, r.to]
  );
}

async function listAttendanceSummary() {
  return safeQuery(
    `SELECT e.emp_code, e.name_ar, COUNT(p.id) AS punch_count, MAX(p.punch_time) AS last_punch
     FROM hr_employee e
     LEFT JOIN hr_att_punch p ON p.employee_id = e.id AND p.punch_time >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
     WHERE e.is_active = 1
     GROUP BY e.id, e.emp_code, e.name_ar
     ORDER BY last_punch DESC
     LIMIT 200`
  );
}

module.exports = {
  dashboardKpis,
  listEmployees,
  reportEmployeesBy,
  reportEmployeesByDepartment,
  reportEmployeesByNationality,
  listDepartments,
  listJobTitles,
  listNationalities,
  listBanks,
  listPayrollComponents,
  listLeaveTypes,
  listDepartureTypes,
  listLeaves,
  listLeaveBalances,
  listDepartures,
  listAdvances,
  listSalaries,
  listOvertime,
  listPunches,
  listAttendanceSummary,
  dateRange,
};
