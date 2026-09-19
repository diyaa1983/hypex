-- مزامنة صلاحيات الشاشات الناقصة (تقارير ضريبة، نسخ احتياطي، هاتف…)
-- تُنفَّذ مرة واحدة؛ المزامنة التلقائية من routes.php تكمل أي إضافة لاحقة.

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_invoice_tax_purchase', 'الضريبة المستحقة على فواتير الشراء', 'report', 215
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_invoice_tax_purchase');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_vat_return_tax_purchase', 'الضريبة المستحقة على مردود الشراء', 'report', 216
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_vat_return_tax_purchase');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_customer_statement', 'كشف حساب عميل', 'report', 225
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_customer_statement');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_supplier_statement', 'كشف حساب مورد', 'report', 226
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_supplier_statement');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'hr_payroll_slip', 'قسيمة الراتب (طباعة)', 'screen', 835
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'hr_payroll_slip');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'hr_salary_slip', 'قسيمة راتب (طباعة)', 'screen', 836
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'hr_salary_slip');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code = 'ADMINS'
  AND s.code IN (
    'report_invoice_tax_purchase',
    'report_vat_return_tax_purchase',
    'report_customer_statement',
    'report_supplier_statement',
    'hr_payroll_slip',
    'hr_salary_slip',
    'report_purchases_by_item'
  );

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'report_invoice_tax'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_invoice_tax_purchase';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'report_vat_return_tax'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_vat_return_tax_purchase';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'report_party_statement'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code IN ('report_customer_statement', 'report_supplier_statement');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_salaries'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code IN ('hr_payroll_slip', 'hr_salary_slip');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'report_purchases'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_purchases_by_item';
