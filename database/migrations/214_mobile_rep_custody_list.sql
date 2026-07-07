-- قائمة العهدات المستلمة على الهاتف

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('m_rep_custody_list', 'هاتف — قائمة العهدات المستلمة', 'screen', 9062);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'm_rep_custody_list'
WHERE g.code IN ('MOBILE', 'ADMINS');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s2.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen s ON s.id = gp.screen_id AND s.code = 'm_rep_load' AND gp.allowed = 1
INNER JOIN sys_screen s2 ON s2.code = 'm_rep_custody_list';
