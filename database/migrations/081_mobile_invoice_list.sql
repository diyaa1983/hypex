-- شاشات الهاتف: عرض الفاتورة وقائمة الفواتير

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('m_sales_invoice_list', 'هاتف — قائمة فواتير المبيعات', 'screen', 9020),
('m_sales_invoice_view', 'هاتف — عرض فاتورة مبيعات', 'screen', 9030);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN ('m_sales_invoice_list', 'm_sales_invoice_view')
WHERE g.code = 'MOBILE';
