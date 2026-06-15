-- شاشات إحداثيات مواقع فواتير البيع (سطح المكتب + الهاتف)

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('sales_invoice_gps', 'إحداثيات مواقع فواتير البيع', 'screen', 196),
('m_sales_invoice_gps', 'هاتف — إحداثيات مواقع فواتير البيع', 'screen', 9040);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_src ON s_src.code = 'sales_documents_list'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_src.id AND gp.allowed = 1
WHERE s_new.code = 'sales_invoice_gps';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_src ON s_src.code = 'm_sales_invoices'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_src.id AND gp.allowed = 1
WHERE s_new.code = 'm_sales_invoice_gps';
