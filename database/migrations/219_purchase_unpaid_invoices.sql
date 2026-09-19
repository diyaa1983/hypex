-- شاشة فواتير الشراء غير المدفوعة (من مؤشر لوحة التحكم)

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'purchase_unpaid_invoices', 'فواتير الشراء غير المدفوعة', 'screen', 245
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'purchase_unpaid_invoices');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code IN (
    'dashboard_kpi_payables',
    'report_supplier_payables',
    'purchase_documents_list',
    'purchase_invoices'
)
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'purchase_unpaid_invoices';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'purchase_unpaid_invoices'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
