-- هاتف: إضافة عميل مربوط بالمندوب

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('m_customer_add', 'هاتف — إضافة عميل', 'screen', 9015);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'm_customer_add'
WHERE g.code IN ('MOBILE', 'ADMINS');
