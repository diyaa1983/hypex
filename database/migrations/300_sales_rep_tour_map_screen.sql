-- خريطة جولة المندوب — شاشة صلاحيات (قائمة المندوبين)

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('sales_rep_tour_map', 'خريطة جولة المندوب', 'screen', 237);

UPDATE sys_screen SET name_ar = 'خريطة جولة المندوب', screen_type = 'screen' WHERE code = 'sales_rep_tour_map';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT 1, s.id, 1 FROM sys_screen s WHERE s.code = 'sales_rep_tour_map';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id AND src.code IN ('sales_rep_route', 'sales_reps')
INNER JOIN sys_screen s ON s.code = 'sales_rep_tour_map'
WHERE gp.allowed = 1;
