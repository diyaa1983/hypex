-- شاشة سلف الموظفين (المحاسبة — صرف السلف المعتمدة من الشؤون)

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'fin_employee_advances', 'سلف الموظفين — المحاسبة', 'screen', 119
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'fin_employee_advances');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code IN ('cash_payment', 'cash_payments_list')
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'fin_employee_advances';
