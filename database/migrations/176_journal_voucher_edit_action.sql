-- صلاحية تعديل سند قيد مرحّل (بعد التحقق بكلمة المرور)

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_edit_journal_voucher', 'تعديل سند قيد مرحّل', 'screen', 9107
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_edit_journal_voucher');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'action_edit_journal_voucher'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id AND src.code = 'journal_voucher'
INNER JOIN sys_screen s ON s.code = 'action_edit_journal_voucher'
WHERE gp.allowed = 1;
