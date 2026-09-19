INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'inventory_align_warehouse', 'مواءمة المخزون مع المستودع', 'screen', 252
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'inventory_align_warehouse');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'journal_entries'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'inventory_align_warehouse';
