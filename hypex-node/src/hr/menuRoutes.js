'use strict';

const { createDomainRouter } = require('../lib/domainFactory');
const { hrCatalog } = require('./catalog');
const q = require('./domainQueries');

function dash(ui, v) {
  const s = v == null || v === '' ? '' : String(v);
  return s === '' ? '—' : ui.esc(s);
}

function simpleTableRows(ui, rows, cols) {
  return (
    rows
      .map((r) => `<tr>${cols.map((c) => `<td>${dash(ui, r[c])}</td>`).join('')}</tr>`)
      .join('') || ui.emptyRow(cols.length)
  );
}

module.exports = createDomainRouter({
  basePath: '/hr',
  mark: 'Hr',
  kicker: 'Hypex HR · Node',
  hubTitle: 'شؤون الموظفين',
  hubSubtitle: 'الموظفون والحضور والإجازات والرواتب بتصميم 2027. الإدخال والترحيل عبر PHP.',
  catalog: hrCatalog,
  listHandlers: {
    '/hr/dashboard': async (req, { ui }) => {
      const k = await q.dashboardKpis();
      const rowsHtml = `<tr>
        <td>موظفون نشطون</td><td class="si-num" dir="ltr">${k.active}</td>
      </tr><tr>
        <td>مستقيلون</td><td class="si-num" dir="ltr">${k.resigned}</td>
      </tr><tr>
        <td>أقسام نشطة</td><td class="si-num" dir="ltr">${k.depts}</td>
      </tr><tr>
        <td>مجموع الرواتب الأساسية + البدلات (نشطون)</td><td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(k.payroll))}</td>
      </tr>`;
      return {
        subtitle: 'مؤشرات سريعة',
        headers: ['المؤشر', 'القيمة'],
        rowsHtml,
        count: 4,
        extraActions: [{ label: 'قائمة الموظفين', href: '/hr/employees', primary: true }],
      };
    },
    // بيانات الموظف: employeesRoutes.js (قائمة + بطاقة إضافة/تعديل)
    // بصمات الموظفين: attendanceRoutes.js
    // أنواع المغادرات: departureTypesRoutes.js
    // مغادرات الموظفين: departuresRoutes.js (إدخال + ترحيل + تقرير)
    '/hr/leave-types': async (req, { ui }) => {
      const rows = await q.listLeaveTypes();
      const keys = rows[0] ? Object.keys(rows[0]).slice(0, 5) : ['id'];
      return {
        headers: keys,
        rowsHtml: simpleTableRows(ui, rows, keys),
        count: rows.length,
      };
    },
    '/hr/leave-balances': async (req, { ui }) => {
      const rows = await q.listLeaveBalances();
      return {
        headers: ['الموظف', 'الرمز', 'نوع الإجازة', 'الرصيد'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td>${dash(ui, r.employee_name)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.emp_code)}</td>
            <td>${dash(ui, r.leave_type)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.remaining)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(4),
        count: rows.length,
      };
    },
    '/hr/salaries': async (req, { ui }) => {
      const rows = await q.listSalaries();
      return {
        headers: ['الموظف', 'الرمز', 'تفاصيل'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td>${dash(ui, r.employee_name)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.emp_code)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.salary_month || r.month || r.period || r.id)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(3),
        count: rows.length,
      };
    },
    '/hr/advances': async (req, { ui }) => {
      const rows = await q.listAdvances({ q: String(req.query.q || '') });
      return {
        headers: ['الموظف', 'الرمز', 'المبلغ', 'التاريخ'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td>${dash(ui, r.employee_name)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.emp_code)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.amount || r.advance_amount || 0))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.advance_date || r.created_at))}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(4),
        count: rows.length,
        searchPath: '/hr/advances',
        qVal: String(req.query.q || ''),
      };
    },
    '/hr/overtime': async (req, { ui }) => {
      const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
      const rows = await q.listOvertime(range);
      return {
        headers: ['الموظف', 'التاريخ', 'الساعات'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td>${dash(ui, r.employee_name)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.ot_date || r.work_date || r.overtime_date))}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.hours || r.ot_hours || r.hours_count)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(3),
        count: rows.length,
        filtersHtml: ui.dateFilters('/hr/overtime', range.from, range.to),
      };
    },
    '/hr/departments': async (req, { ui }) => {
      const rows = await q.listDepartments();
      return {
        headers: ['الرمز', 'الاسم', 'الحالة'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.dept_code)}</td>
            <td>${ui.esc(r.name_ar || '')}</td>
            <td>${ui.statusPill(Number(r.is_active) === 1 ? 'ok' : 'lock', Number(r.is_active) === 1 ? 'نشط' : 'موقوف')}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(3),
        count: rows.length,
      };
    },
    '/hr/job-titles': async (req, { ui }) => {
      const rows = await q.listJobTitles();
      return {
        headers: ['الرمز', 'المسمى', 'الحالة'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.title_code)}</td>
            <td>${ui.esc(r.name_ar || '')}</td>
            <td>${ui.statusPill(Number(r.is_active) === 1 ? 'ok' : 'lock', Number(r.is_active) === 1 ? 'نشط' : 'موقوف')}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(3),
        count: rows.length,
      };
    },
    '/hr/nationalities': async (req, { ui }) => {
      const rows = await q.listNationalities();
      return {
        headers: ['الرمز', 'الاسم', 'الحالة'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.code)}</td>
            <td>${ui.esc(r.name_ar || '')}</td>
            <td>${ui.statusPill(Number(r.is_active) === 1 ? 'ok' : 'lock', Number(r.is_active) === 1 ? 'نشط' : 'موقوف')}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(3),
        count: rows.length,
      };
    },
    '/hr/banks': async (req, { ui }) => {
      const rows = await q.listBanks();
      return {
        headers: ['الرمز', 'الاسم', 'الحالة'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.bank_code)}</td>
            <td>${ui.esc(r.name_ar || '')}</td>
            <td>${ui.statusPill(Number(r.is_active) === 1 ? 'ok' : 'lock', Number(r.is_active) === 1 ? 'نشط' : 'موقوف')}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(3),
        count: rows.length,
      };
    },
    '/hr/payroll-components': async (req, { ui }) => {
      const rows = await q.listPayrollComponents();
      const keys = rows[0] ? Object.keys(rows[0]).slice(0, 6) : ['id'];
      return {
        headers: keys,
        rowsHtml: simpleTableRows(ui, rows, keys),
        count: rows.length,
      };
    },
  },
  reportHandlers: {
    '/hr/reports/employees': async (req, { ui }) => {
      const rows = await q.listEmployees({ activeOnly: false });
      return {
        useDateFilters: false,
        headers: ['الرمز', 'الاسم', 'القسم', 'المسمى', 'الراتب', 'الحالة'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.emp_code)}</td>
            <td>${ui.esc(r.name_ar || '')}</td>
            <td>${dash(ui, r.department)}</td>
            <td>${dash(ui, r.job_title)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.base_salary))}</td>
            <td>${Number(r.is_active) === 1 ? 'نشط' : 'موقوف'}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(6),
        count: rows.length,
      };
    },
    '/hr/reports/by-dept': async (req, { ui }) => {
      const status = String(req.query.status || 'all');
      const departmentId = Number(req.query.department_id || 0) || 0;
      const report = await q.reportEmployeesByDepartment({ status, departmentId });
      const depts = await q.listDepartments();
      const statusOpts = [
        ['all', 'الكل'],
        ['active', 'على رأس العمل'],
        ['resigned', 'مستقيل'],
      ];
      const stLabel = Object.fromEntries(statusOpts)[report.status] || 'الكل';
      let deptLabel = 'جميع الأقسام';
      if (departmentId > 0) {
        const found = depts.find((d) => Number(d.id) === departmentId);
        if (found) deptLabel = found.name_ar || deptLabel;
      }

      const showStatus = report.status === 'all';
      const colSpan = showStatus ? 7 : 6;

      const blocksHtml =
        report.departments.length === 0
          ? `<p class="hr-dept-empty">لا يوجد موظفون مطابقون للفلتر.
               <a href="/hr/employees/add">أضف موظفاً</a> أولاً إن كانت القائمة فارغة.</p>`
          : report.departments
              .map((block) => {
                const rows = block.rows
                  .map(
                    (r) => `<tr>
                  <td class="si-num">${r.seq}</td>
                  <td class="si-num" dir="ltr">${dash(ui, r.emp_code)}</td>
                  <td>${ui.esc(r.name_ar || '')}</td>
                  <td>${dash(ui, r.job_title_name)}</td>
                  <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.hire_date))}</td>
                  <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.salary))}</td>
                  ${showStatus ? `<td>${ui.esc(r.status_label || '')}</td>` : ''}
                </tr>`
                  )
                  .join('');
                return `
                <section class="hr-dept-block si-surface" style="margin-top:.75rem">
                  <div class="si-surface-head">
                    <h2>القسم: ${ui.esc(block.dept_name)}
                      <span style="font-weight:600;color:#64748b;font-size:.85rem"> (${block.employee_count} موظف)</span>
                    </h2>
                    <span class="si-count" dir="ltr">${ui.esc(ui.fmtAmt(block.total_salary))}</span>
                  </div>
                  <div class="si-table-wrap">
                    <table class="si-table">
                      <thead><tr>
                        <th>تسلسل</th><th>رقم الموظف</th><th>اسم الموظف</th>
                        <th>المسمى الوظيفي</th><th>تاريخ التعيين</th><th>الراتب</th>
                        ${showStatus ? '<th>الحالة</th>' : ''}
                      </tr></thead>
                      <tbody>${rows}</tbody>
                      <tfoot><tr>
                        <td colspan="${colSpan - 1}"><strong>مجموع القسم</strong></td>
                        <td class="si-num" dir="ltr"><strong>${ui.esc(ui.fmtAmt(block.total_salary))}</strong></td>
                        ${showStatus ? '<td></td>' : ''}
                      </tr></tfoot>
                    </table>
                  </div>
                </section>`;
              })
              .join('');

      const deptOpts = depts
        .map(
          (d) =>
            `<option value="${d.id}" ${departmentId === Number(d.id) ? 'selected' : ''}>${ui.esc(
              d.name_ar || ''
            )}</option>`
        )
        .join('');
      const statusHtml = statusOpts
        .map(
          ([v, lab]) =>
            `<option value="${v}" ${report.status === v ? 'selected' : ''}>${ui.esc(lab)}</option>`
        )
        .join('');

      const filtersHtml = `
        <div class="si-rail no-print">
          <form class="si-search" method="get" action="/hr/reports/by-dept"
                style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.45rem;align-items:flex-end">
            <label style="display:grid;gap:.25rem;font-size:.78rem;font-weight:700;color:#5c6578">القسم
              <select class="si-field" name="department_id" style="min-width:12rem;min-height:2.2rem">
                <option value="0">جميع الأقسام</option>
                ${deptOpts}
              </select>
            </label>
            <label style="display:grid;gap:.25rem;font-size:.78rem;font-weight:700;color:#5c6578">الحالة
              <select class="si-field" name="status" style="min-width:10rem;min-height:2.2rem">
                ${statusHtml}
              </select>
            </label>
            <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
            <button type="button" class="si-btn si-btn--print" data-print="1">🖨 طباعة</button>
          </form>
        </div>
        <div class="si-print-meta" style="padding:.35rem 0;font-size:.88rem;color:#475569">
          <strong>القسم:</strong> ${ui.esc(deptLabel)}
          &nbsp;|&nbsp;
          <strong>الحالة:</strong> ${ui.esc(stLabel)}
          &nbsp;|&nbsp;
          <strong>عدد الموظفين:</strong> <span dir="ltr">${report.grand.employee_count}</span>
          &nbsp;|&nbsp;
          <strong>إجمالي الرواتب:</strong> <span dir="ltr">${ui.esc(ui.fmtAmt(report.grand.total_salary))}</span>
        </div>`;

      return {
        useDateFilters: false,
        filtersHtml,
        headers: [],
        rowsHtml: '',
        count: report.grand.employee_count,
        extraHtml: blocksHtml,
        subtitle: 'تقرير مفصّل للموظفين مجمّعين حسب القسم',
      };
    },
    '/hr/reports/by-nationality': async (req, { ui }) => {
      const status = String(req.query.status || 'all');
      const nationalityId = Number(req.query.nationality_id || 0) || 0;
      const report = await q.reportEmployeesByNationality({ status, nationalityId });
      const nats = await q.listNationalities();
      const statusOpts = [
        ['all', 'الكل'],
        ['active', 'على رأس العمل'],
        ['resigned', 'مستقيل'],
      ];
      const stLabel = Object.fromEntries(statusOpts)[report.status] || 'الكل';
      let natLabel = 'جميع الجنسيات';
      if (nationalityId > 0) {
        const found = nats.find((n) => Number(n.id) === nationalityId);
        if (found) natLabel = found.name_ar || natLabel;
      }

      const showStatus = report.status === 'all';
      const colSpan = showStatus ? 7 : 6;

      const blocksHtml =
        report.nationalities.length === 0
          ? `<p class="hr-dept-empty">لا يوجد موظفون مطابقون للفلتر.
               <a href="/hr/employees/add">أضف موظفاً</a> وعيّن الجنسية في البطاقة.</p>`
          : report.nationalities
              .map((block) => {
                const rows = block.rows
                  .map(
                    (r) => `<tr>
                  <td class="si-num">${r.seq}</td>
                  <td class="si-num" dir="ltr">${dash(ui, r.emp_code)}</td>
                  <td>${ui.esc(r.name_ar || '')}</td>
                  <td>${dash(ui, r.job_title_name)}</td>
                  <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.hire_date))}</td>
                  <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.salary))}</td>
                  ${showStatus ? `<td>${ui.esc(r.status_label || '')}</td>` : ''}
                </tr>`
                  )
                  .join('');
                return `
                <section class="hr-dept-block si-surface" style="margin-top:.75rem">
                  <div class="si-surface-head">
                    <h2>الجنسية: ${ui.esc(block.nat_name)}
                      <span style="font-weight:600;color:#64748b;font-size:.85rem"> (${block.employee_count} موظف)</span>
                    </h2>
                    <span class="si-count" dir="ltr">${ui.esc(ui.fmtAmt(block.total_salary))}</span>
                  </div>
                  <div class="si-table-wrap">
                    <table class="si-table">
                      <thead><tr>
                        <th>تسلسل</th><th>رقم الموظف</th><th>اسم الموظف</th>
                        <th>المسمى الوظيفي</th><th>تاريخ التعيين</th><th>الراتب</th>
                        ${showStatus ? '<th>الحالة</th>' : ''}
                      </tr></thead>
                      <tbody>${rows}</tbody>
                      <tfoot><tr>
                        <td colspan="${colSpan - 1}"><strong>مجموع الجنسية</strong></td>
                        <td class="si-num" dir="ltr"><strong>${ui.esc(ui.fmtAmt(block.total_salary))}</strong></td>
                        ${showStatus ? '<td></td>' : ''}
                      </tr></tfoot>
                    </table>
                  </div>
                </section>`;
              })
              .join('');

      const natOpts = nats
        .map(
          (n) =>
            `<option value="${n.id}" ${nationalityId === Number(n.id) ? 'selected' : ''}>${ui.esc(
              n.name_ar || ''
            )}</option>`
        )
        .join('');
      const statusHtml = statusOpts
        .map(
          ([v, lab]) =>
            `<option value="${v}" ${report.status === v ? 'selected' : ''}>${ui.esc(lab)}</option>`
        )
        .join('');

      const filtersHtml = `
        <div class="si-rail no-print">
          <form class="si-search" method="get" action="/hr/reports/by-nationality"
                style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.45rem;align-items:flex-end">
            <label style="display:grid;gap:.25rem;font-size:.78rem;font-weight:700;color:#5c6578">الجنسية
              <select class="si-field" name="nationality_id" style="min-width:12rem;min-height:2.2rem">
                <option value="0">جميع الجنسيات</option>
                ${natOpts}
              </select>
            </label>
            <label style="display:grid;gap:.25rem;font-size:.78rem;font-weight:700;color:#5c6578">الحالة
              <select class="si-field" name="status" style="min-width:10rem;min-height:2.2rem">
                ${statusHtml}
              </select>
            </label>
            <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
            <button type="button" class="si-btn si-btn--print" data-print="1">🖨 طباعة</button>
          </form>
        </div>
        <div class="si-print-meta" style="padding:.35rem 0;font-size:.88rem;color:#475569">
          <strong>الجنسية:</strong> ${ui.esc(natLabel)}
          &nbsp;|&nbsp;
          <strong>الحالة:</strong> ${ui.esc(stLabel)}
          &nbsp;|&nbsp;
          <strong>عدد الموظفين:</strong> <span dir="ltr">${report.grand.employee_count}</span>
          &nbsp;|&nbsp;
          <strong>إجمالي الرواتب:</strong> <span dir="ltr">${ui.esc(ui.fmtAmt(report.grand.total_salary))}</span>
        </div>`;

      return {
        useDateFilters: false,
        filtersHtml,
        headers: [],
        rowsHtml: '',
        count: report.grand.employee_count,
        extraHtml: blocksHtml,
        subtitle: 'تقرير مفصّل للموظفين مجمّعين حسب الجنسية',
      };
    },
    '/hr/reports/resigned': async (req, { ui }) => {
      const rows = await q.listEmployees({ resignedOnly: true, activeOnly: false });
      return {
        useDateFilters: false,
        headers: ['الرمز', 'الاسم', 'القسم', 'تاريخ الاستقالة'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.emp_code)}</td>
            <td>${ui.esc(r.name_ar || '')}</td>
            <td>${dash(ui, r.department)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.resignation_date))}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(4),
        count: rows.length,
      };
    },
    '/hr/reports/punches': async (req, { ui }) => {
      const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
      const rows = await q.listPunches(range);
      return {
        headers: ['الموظف', 'الرمز', 'وقت البصمة', 'النوع', 'الجهاز'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td>${dash(ui, r.employee_name)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.emp_code)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.punch_time)}</td>
            <td>${dash(ui, r.punch_type)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.device_sn)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(5),
        count: rows.length,
        from: range.from,
        to: range.to,
      };
    },
    // تقرير المغادرات: departuresRoutes.js
    '/hr/reports/leaves': async (req, { ui }) => {
      const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
      const rows = await q.listLeaves(range);
      return {
        headers: ['الموظف', 'النوع', 'من', 'إلى', 'أيام'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td>${dash(ui, r.employee_name)}</td>
            <td>${dash(ui, r.leave_type)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.date_from || r.leave_date))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.date_to || r.leave_date))}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.days_count)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(5),
        count: rows.length,
        from: range.from,
        to: range.to,
      };
    },
    '/hr/reports/leave-balances': async (req, { ui }) => {
      const rows = await q.listLeaveBalances();
      return {
        useDateFilters: false,
        headers: ['الموظف', 'نوع الإجازة', 'الرصيد'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td>${dash(ui, r.employee_name)}</td>
            <td>${dash(ui, r.leave_type)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.remaining)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(3),
        count: rows.length,
      };
    },
    '/hr/reports/advances': async (req, { ui }) => {
      const rows = await q.listAdvances({});
      return {
        useDateFilters: false,
        headers: ['الموظف', 'المبلغ', 'التاريخ'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td>${dash(ui, r.employee_name)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.amount || r.advance_amount || 0))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.advance_date || r.created_at))}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(3),
        count: rows.length,
      };
    },
    '/hr/reports/overtime': async (req, { ui }) => {
      const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
      const rows = await q.listOvertime(range);
      return {
        headers: ['الموظف', 'التاريخ', 'الساعات'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td>${dash(ui, r.employee_name)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.ot_date || r.work_date))}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.hours || r.ot_hours)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(3),
        count: rows.length,
        from: range.from,
        to: range.to,
      };
    },
  },
});
