-- هاتف: كشف حركات المبيعات

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('m_sales_movement', 'هاتف — كشف حركات المبيعات', 'screen', 9048);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'm_sales_movement'
WHERE g.code = 'MOBILE';
