-- شاشتان منفصلتان لمزامنة البصمة: السيرفر (ZKT) و Windows (محلي)

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'hr_attendance_sync_server', 'مزامنة البصمة — السيرفر', 'screen', 829
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'hr_attendance_sync_server');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'hr_attendance_sync_local', 'مزامنة البصمة — Windows', 'screen', 830
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'hr_attendance_sync_local');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_employee_attendance'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code IN ('hr_attendance_sync_server', 'hr_attendance_sync_local');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN ('hr_attendance_sync_server', 'hr_attendance_sync_local')
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
