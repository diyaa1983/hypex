INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'oracle_customers_sync', 'تكامل Oracle — العملاء', 'screen', 55
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'oracle_customers_sync');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'oracle_customers_sync'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
