-- شاشات مواقع المستخدمين (GPS) — سطح المكتب + الهاتف

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('user_gps_locations', 'مواقع المستخدمين (GPS)', 'screen', 312),
('m_user_gps_locations', 'هاتف — مواقع المستخدمين', 'screen', 9050);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_src ON s_src.code = 'users'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_src.id AND gp.allowed = 1
WHERE s_new.code = 'user_gps_locations';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_src ON s_src.code = 'm_home'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_src.id AND gp.allowed = 1
WHERE s_new.code = 'm_user_gps_locations';
