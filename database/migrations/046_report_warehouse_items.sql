-- تقرير المواد في المستودع
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_warehouse_items', 'تقرير المواد', 'report', 221
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_warehouse_items');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'report_inventory'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_warehouse_items';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'report_warehouse_items'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
