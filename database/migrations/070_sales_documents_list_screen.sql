-- شاشة قائمة فواتير المبيعات والمرتجعات
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'sales_documents_list', 'قائمة الفواتير', 'screen', 194
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'sales_documents_list');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_inv ON s_inv.code = 'sales_invoices'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_inv.id AND gp.allowed = 1
WHERE s_new.code = 'sales_documents_list';
