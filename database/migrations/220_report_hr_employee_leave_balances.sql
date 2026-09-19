-- تقرير أرصدة الإجازات لجميع الموظفين

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_hr_employee_leave_balances', 'تقرير أرصدة الإجازات لجميع الموظفين', 'report', 187
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_hr_employee_leave_balances');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_employee_leave_balances'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_hr_employee_leave_balances';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'report_hr_employee_leave_balances'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
