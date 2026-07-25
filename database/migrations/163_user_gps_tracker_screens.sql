-- شاشة تتبّع المواقع الحية (خريطة) — سطح المكتب + الهاتف

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('user_gps_tracker', 'تتبّع المواقع الحية (خريطة)', 'screen', 313),
('m_user_gps_tracker', 'هاتف — تتبّع المواقع الحية', 'screen', 9051);

-- منح الصلاحية للمجموعات التي تملك بالفعل شاشات مواقع المستخدمين
INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_src ON s_src.code = 'user_gps_locations'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_src.id AND gp.allowed = 1
WHERE s_new.code = 'user_gps_tracker';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_src ON s_src.code = 'm_user_gps_locations'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_src.id AND gp.allowed = 1
WHERE s_new.code = 'm_user_gps_tracker';

-- احتياطي: منح من شاشة المستخدمين / الرئيسية للهاتف إن لم تُنسخ أعلاه
INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_src ON s_src.code = 'users'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_src.id AND gp.allowed = 1
WHERE s_new.code = 'user_gps_tracker';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_src ON s_src.code = 'm_home'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_src.id AND gp.allowed = 1
WHERE s_new.code = 'm_user_gps_tracker';
