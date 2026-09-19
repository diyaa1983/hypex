-- تقرير الشيكات الصادرة + تصحيح أسماء تقارير الشيكات

UPDATE sys_screen SET name_ar = 'تقرير الشيكات الواردة' WHERE code = 'report_incoming_checks';

DELETE gp FROM sys_group_permission gp
INNER JOIN sys_screen s ON s.id = gp.screen_id
WHERE s.code = 'report_incoming_checks_summary';

DELETE FROM sys_screen WHERE code = 'report_incoming_checks_summary';

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_outgoing_checks', 'تقرير الشيكات الصادرة', 'report', 251
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_outgoing_checks');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code IN ('report_incoming_checks', 'report_vouchers', 'report_supplier_payables')
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_outgoing_checks';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'report_outgoing_checks'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
