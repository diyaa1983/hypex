-- فئات المواد ووحدات القياس في صلاحيات الشاشات
USE namma_erp;

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('item_categories', 'فئات المواد', 'screen', 145),
('item_units', 'وحدات القياس', 'screen', 148);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT 1, s.id, 1 FROM sys_screen s WHERE s.code IN ('item_categories', 'item_units');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen items ON items.id = gp.screen_id AND items.code = 'items'
INNER JOIN sys_screen s ON s.code IN ('item_categories', 'item_units')
WHERE gp.allowed = 1;
