UPDATE sys_screen SET name_ar = 'كشف ذمم العملاء' WHERE code = 'report_receivables';

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_supplier_payables', 'كشف ذمم الموردين', 'report', 249
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_supplier_payables');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'report_receivables'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_supplier_payables';
