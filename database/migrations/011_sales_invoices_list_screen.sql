-- شاشة مستقلة: فواتير البيع مرحّلة / غير مرحّلة
UPDATE sys_screen SET name_ar = 'فواتير البيع — مرحّلة وغير مرحّلة'
WHERE code = 'sales_invoices_list';

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'sales_invoices_list', 'فواتير البيع — مرحّلة وغير مرحّلة', 'screen', 195
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'sales_invoices_list');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_list.id, 1
FROM sys_screen s_list
INNER JOIN sys_screen s_inv ON s_inv.code = 'sales_invoices'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_inv.id AND gp.allowed = 1
WHERE s_list.code = 'sales_invoices_list';
