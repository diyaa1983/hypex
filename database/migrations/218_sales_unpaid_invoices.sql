-- شاشة فواتير البيع غير المسددة (من مؤشر لوحة التحكم)

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'sales_unpaid_invoices', 'فواتير البيع غير المسددة', 'screen', 197
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'sales_unpaid_invoices');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code IN (
    'dashboard_kpi_receivables',
    'report_receivables',
    'sales_documents_list',
    'sales_invoices'
)
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'sales_unpaid_invoices';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'sales_unpaid_invoices'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
