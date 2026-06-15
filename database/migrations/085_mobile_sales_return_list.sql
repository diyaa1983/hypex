-- هاتف: قائمة مرتجعات المبيعات

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('m_sales_returns_list', 'هاتف — قائمة مرتجعات المبيعات', 'screen', 9045);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'm_sales_returns_list'
WHERE g.code = 'MOBILE';

UPDATE sys_screen SET name_ar = 'هاتف — مرتجع مبيعات (جديد)' WHERE code = 'm_sales_returns';
