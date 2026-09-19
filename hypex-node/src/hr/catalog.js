'use strict';

/** كتالوج شؤون الموظفين — مطابق nav_menu */
const hrCatalog = [
  {
    group: 'employees',
    title: 'بيانات الموظفين',
    items: [
      { r: 'hr_dashboard', label: 'مؤشرات رئيسية', icon: '📊', path: '/hr/dashboard', kind: 'list' },
      { r: 'hr_employees', label: 'بيانات الموظف الاساسية', icon: '👤', path: '/hr/employees', kind: 'list' },
      { r: 'report_hr_employees', label: 'تقرير الموظفين', icon: '📋', path: '/hr/reports/employees', kind: 'report' },
      { r: 'report_hr_employees_by_department', label: 'الموظفين حسب القسم', icon: '🏛', path: '/hr/reports/by-dept', kind: 'report' },
      { r: 'report_hr_employees_by_nationality', label: 'الموظفين حسب الجنسية', icon: '🌍', path: '/hr/reports/by-nationality', kind: 'report' },
      { r: 'report_hr_employees_resigned', label: 'الموظفين المستقيلين', icon: '📤', path: '/hr/reports/resigned', kind: 'report' },
    ],
  },
  {
    group: 'attendance',
    title: 'بصمات الموظفين',
    items: [
      { r: 'hr_employee_attendance', label: 'بصمات الموظفين', icon: '👆', path: '/hr/attendance', kind: 'list' },
      { r: 'hr_attendance_sync_server', label: 'مزامنة السيرفر (ZKT)', icon: '🌐', path: '/hr/attendance/sync-server', kind: 'list' },
      { r: 'hr_attendance_sync_local', label: 'مزامنة Windows (محلي)', icon: '💻', path: '/hr/attendance/sync-local', kind: 'list' },
      { r: 'hr_employee_schedule', label: 'تعريف دوام الموظف', icon: '📅', path: '/hr/attendance/schedule', kind: 'list' },
      { r: 'hr_attendance_settings', label: 'إعدادات دوام الموظفين', icon: '⚙', path: '/hr/attendance/settings', kind: 'list' },
      { r: 'report_hr_employee_attendance', label: 'حركة دوام الموظفين', icon: '🕐', path: '/hr/reports/attendance', kind: 'bridge' },
      { r: 'report_hr_att_punch_movements', label: 'حركات البصمات (الكل)', icon: '👆', path: '/hr/reports/punches', kind: 'report' },
    ],
  },
  {
    group: 'departures',
    title: 'المغادرات',
    items: [
      { r: 'hr_departure_types', label: 'أنواع المغادرات', icon: '📋', path: '/hr/departure-types', kind: 'list' },
      { r: 'hr_employee_departures', label: 'مغادرات الموظفين', icon: '🚪', path: '/hr/departures', kind: 'list' },
      { r: 'report_hr_employee_departures', label: 'تقرير المغادرات بين تاريخين', icon: '📊', path: '/hr/reports/departures', kind: 'report' },
    ],
  },
  {
    group: 'leaves',
    title: 'الإجازات',
    items: [
      { r: 'hr_leave_types', label: 'إعدادات الإجازات', icon: '📋', path: '/hr/leave-types', kind: 'list' },
      { r: 'hr_employee_leave_balances', label: 'رصيد إجازات الموظفين', icon: '📊', path: '/hr/leave-balances', kind: 'list' },
      { r: 'hr_employee_leaves', label: 'إدخال الإجازات', icon: '🏖', path: '/hr/leaves/entry', kind: 'bridge' },
      { r: 'report_hr_employee_leaves', label: 'تقرير الإجازات بين تاريخين', icon: '📊', path: '/hr/reports/leaves', kind: 'report' },
      { r: 'report_hr_employee_leave_balances', label: 'أرصدة الإجازات لجميع الموظفين', icon: '📋', path: '/hr/reports/leave-balances', kind: 'report' },
    ],
  },
  {
    group: 'salaries',
    title: 'الرواتب',
    items: [
      { r: 'hr_salaries', label: 'رواتب الموظفين', icon: '💵', path: '/hr/salaries', kind: 'list' },
      { r: 'hr_monthly_payroll_adjustments', label: 'علاوات واقتطاعات شهرية', icon: '📅', path: '/hr/payroll-adjustments', kind: 'bridge' },
      { r: 'hr_employee_advances', label: 'سلف الموظفين', icon: '💳', path: '/hr/advances', kind: 'list' },
      { r: 'hr_payroll_posting', label: 'قيد الرواتب', icon: '📋', path: '/hr/payroll-posting', kind: 'bridge' },
      { r: 'hr_payroll_month_report', label: 'تقرير قيود الرواتب حسب الشهر', icon: '🖨', path: '/hr/reports/payroll-month', kind: 'bridge' },
      { r: 'hr_payroll_dept_report', label: 'كشف الرواتب للأقسام', icon: '📑', path: '/hr/reports/payroll-dept', kind: 'bridge' },
      { r: 'hr_payroll_ss_report', label: 'كشف الضمان الاجتماعي', icon: '🛡️', path: '/hr/reports/payroll-ss', kind: 'bridge' },
      { r: 'hr_payroll_income_tax_report', label: 'كشف ضريبة الدخل', icon: '🧮', path: '/hr/reports/payroll-tax', kind: 'bridge' },
      { r: 'hr_payroll_bank_transfer_report', label: 'كشف تحويل الرواتب للبنوك', icon: '🏦', path: '/hr/reports/payroll-bank', kind: 'bridge' },
      { r: 'hr_payroll_slip_report', label: 'قسيمة الراتب', icon: '🧾', path: '/hr/reports/payroll-slip', kind: 'bridge' },
      { r: 'report_hr_employee_advances', label: 'تقرير سلف الموظفين', icon: '💳', path: '/hr/reports/advances', kind: 'report' },
    ],
  },
  {
    group: 'overtime',
    title: 'العمل الإضافي',
    items: [
      { r: 'hr_overtime_settings', label: 'إعدادات العمل الإضافي', icon: '⚙', path: '/hr/overtime/settings', kind: 'bridge' },
      { r: 'hr_employee_overtime', label: 'تسجيل ساعات العمل الإضافي', icon: '⏱', path: '/hr/overtime', kind: 'list' },
      { r: 'report_hr_employee_overtime', label: 'تقرير العمل الإضافي', icon: '⏱', path: '/hr/reports/overtime', kind: 'report' },
    ],
  },
  {
    group: 'settings',
    title: 'إعدادات الرواتب',
    items: [
      { r: 'hr_departments', label: 'الأقسام', icon: '🏛', path: '/hr/departments', kind: 'list' },
      { r: 'hr_job_titles', label: 'المسميات الوظيفية', icon: '💼', path: '/hr/job-titles', kind: 'list' },
      { r: 'hr_nationalities', label: 'الجنسيات', icon: '🌍', path: '/hr/nationalities', kind: 'list' },
      { r: 'hr_payroll_components', label: 'إعداد العلاوات والاقتطاعات', icon: '➕➖', path: '/hr/payroll-components', kind: 'list' },
      { r: 'hr_salary_banks', label: 'البنوك', icon: '🏦', path: '/hr/banks', kind: 'list' },
      { r: 'hr_employee_bank_link', label: 'ربط إعدادات البنك', icon: '🔗', path: '/hr/bank-link', kind: 'bridge' },
      { r: 'hr_social_security_rates', label: 'نسب الضمان الاجتماعي', icon: '📊', path: '/hr/ss-rates', kind: 'bridge' },
      { r: 'hr_income_tax_settings', label: 'إعدادات ضريبة الدخل', icon: '🧮', path: '/hr/tax-settings', kind: 'bridge' },
      { r: 'hr_social_security', label: 'قيود الضمان', icon: '🛡', path: '/hr/social-security', kind: 'bridge' },
    ],
  },
];

function flatHrItems() {
  return hrCatalog.flatMap((g) => g.items.map((it) => ({ ...it, group: g.group })));
}

module.exports = { hrCatalog, flatHrItems };
