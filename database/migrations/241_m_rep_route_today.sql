-- شاشة خط سير اليوم للمندوب (تطبيق الهاتف)
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'm_rep_route_today', 'خط سير اليوم', 'screen', 241
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'm_rep_route_today');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'm_rep_route_today'
WHERE g.code IN ('MOBILE', 'ADMINS', 'administrators', 'admin');
