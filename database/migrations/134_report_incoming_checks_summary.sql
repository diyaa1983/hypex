-- تقرير الشيكات الواردة — إجمالي حسب العميل

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_incoming_checks_summary', 'تقرير الشيكات الواردة — إجمالي', 'report', 250
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_incoming_checks_summary');

UPDATE sys_screen SET name_ar = 'تقرير الشيكات الواردة — تفصيلي' WHERE code = 'report_incoming_checks';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code IN ('report_incoming_checks', 'report_receivables', 'report_vouchers')
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_incoming_checks_summary';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'report_incoming_checks_summary'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
