-- كشف حساب مورد - عميل (تقرير موحّد)
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_party_statement', 'كشف حساب مورد - عميل', 'report', 249
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_party_statement');

UPDATE sys_screen SET name_ar = 'كشف حساب مورد - عميل' WHERE code IN ('report_customer_statement', 'report_supplier_statement');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code IN ('report_customer_statement', 'report_supplier_statement')
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_party_statement';
