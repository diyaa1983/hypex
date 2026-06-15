-- هاتف: مرتجع مبيعات

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('m_sales_returns', 'هاتف — مرتجع مبيعات', 'screen', 9040);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'm_sales_returns'
WHERE g.code = 'MOBILE';
