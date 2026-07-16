-- الأرصدة الافتتاحية للحسابات
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'acc_opening_balance', 'الأرصدة الافتتاحية', 'screen', 160
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'acc_opening_balance');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code = 'ADMINS' AND s.code = 'acc_opening_balance';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'journal_entries'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'acc_opening_balance';
