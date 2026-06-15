-- شجرة الحسابات — صلاحية الشاشة
INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('chart_of_accounts', 'شجرة الحسابات', 'screen', 115);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT 1, s.id, 1 FROM sys_screen s WHERE s.code = 'chart_of_accounts';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id AND src.code = 'journal_entries'
INNER JOIN sys_screen s ON s.code = 'chart_of_accounts'
WHERE gp.allowed = 1;
