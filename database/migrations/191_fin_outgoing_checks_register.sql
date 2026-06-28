-- سجل الشيكات الصادرة: رقم تسلسلي لكل شيك صادر من سندات الصرف

ALTER TABLE fin_voucher_check
    ADD COLUMN register_no VARCHAR(40) NULL AFTER id,
    ADD UNIQUE KEY uq_fvc_register_no (register_no);

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'fin_outgoing_checks', 'سجل الشيكات الصادرة', 'screen', 119
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'fin_outgoing_checks');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code IN ('cash_payment', 'fin_checks')
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'fin_outgoing_checks';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'fin_outgoing_checks'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
