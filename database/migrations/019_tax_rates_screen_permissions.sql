-- صلاحيات شاشة «معدّلات الضريبة» مثل «الإعدادات» لكل مجموعة لديها صلاحية إعدادات

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, n.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen s ON s.id = gp.screen_id AND s.code = 'settings' AND gp.allowed = 1
INNER JOIN sys_screen n ON n.code = 'tax_rates_settings';
