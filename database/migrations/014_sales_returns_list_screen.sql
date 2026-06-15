-- شاشة: ترحيل مرتجعات المبيعات
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'sales_returns_list', 'ترحيل مرتجعات المبيعات', 'screen', 196
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'sales_returns_list');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_list.id, 1
FROM sys_screen s_list
INNER JOIN sys_screen s_ret ON s_ret.code = 'sales_returns'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_ret.id AND gp.allowed = 1
WHERE s_list.code = 'sales_returns_list';
