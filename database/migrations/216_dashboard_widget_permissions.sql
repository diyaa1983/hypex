-- صلاحيات مؤشرات لوحة التحكم — كل عنصر في الشاشة الرئيسية قابل للمنح حسب المجموعة

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'dashboard_kpi_sales', 'مؤشرات المبيعات (لوحة التحكم)', 'dashboard', 11
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'dashboard_kpi_sales');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'dashboard_kpi_purchases', 'مؤشر المشتريات (لوحة التحكم)', 'dashboard', 12
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'dashboard_kpi_purchases');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'dashboard_kpi_cashflow', 'مؤشرات المقبوضات والصرفيات (لوحة التحكم)', 'dashboard', 13
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'dashboard_kpi_cashflow');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'dashboard_kpi_receivables', 'مؤشر ذمم العملاء (لوحة التحكم)', 'dashboard', 14
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'dashboard_kpi_receivables');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'dashboard_kpi_payables', 'مؤشر ذمم الموردين (لوحة التحكم)', 'dashboard', 15
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'dashboard_kpi_payables');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'dashboard_panel_treasury', 'لوحة الصندوق والحسابات (لوحة التحكم)', 'dashboard', 16
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'dashboard_panel_treasury');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'dashboard_panel_liabilities', 'لوحة المستحقات (لوحة التحكم)', 'dashboard', 17
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'dashboard_panel_liabilities');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'dashboard_panel_checks', 'إشعارات الشيكات (لوحة التحكم)', 'dashboard', 18
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'dashboard_panel_checks');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'dashboard_panel_recent_sales', 'آخر فواتير المبيعات (لوحة التحكم)', 'dashboard', 19
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'dashboard_panel_recent_sales');

-- منح المؤشرات لكل مجموعة لديها صلاحية لوحة التحكم (توافق مع السلوك السابق)
INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'dashboard'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code IN (
    'dashboard_kpi_sales',
    'dashboard_kpi_purchases',
    'dashboard_kpi_cashflow',
    'dashboard_kpi_receivables',
    'dashboard_kpi_payables',
    'dashboard_panel_treasury',
    'dashboard_panel_liabilities',
    'dashboard_panel_checks',
    'dashboard_panel_recent_sales'
);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN (
    'dashboard_kpi_sales',
    'dashboard_kpi_purchases',
    'dashboard_kpi_cashflow',
    'dashboard_kpi_receivables',
    'dashboard_kpi_payables',
    'dashboard_panel_treasury',
    'dashboard_panel_liabilities',
    'dashboard_panel_checks',
    'dashboard_panel_recent_sales'
)
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
