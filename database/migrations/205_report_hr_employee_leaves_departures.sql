-- تقارير الإجازات والمغادرات بين تاريخين

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_hr_employee_leaves', 'تقرير الإجازات بين تاريخين', 'report', 185
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_hr_employee_leaves');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_hr_employee_departures', 'تقرير المغادرات بين تاريخين', 'report', 186
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_hr_employee_departures');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_employee_leaves'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_hr_employee_leaves';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_employee_departures'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_hr_employee_departures';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN ('report_hr_employee_leaves', 'report_hr_employee_departures')
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
