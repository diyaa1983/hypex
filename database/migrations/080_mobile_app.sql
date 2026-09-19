-- تطبيق الهاتف: مجموعة MOBILE وشاشات m_*

INSERT IGNORE INTO sys_group (code, name_ar, description) VALUES
('MOBILE', 'هاتف', 'صلاحية الدخول لتطبيق الهاتف المحمول');

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('m_home', 'هاتف — الرئيسية', 'screen', 9000),
('m_sales_invoices', 'هاتف — فاتورة مبيعات', 'screen', 9010);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN ('m_home', 'm_sales_invoices')
WHERE g.code = 'MOBILE';

-- مدير النظام في مجموعة الهاتف للتجربة (يمكن إزالته لاحقاً من واجهة المجموعات)
INSERT IGNORE INTO sys_user_group (user_id, group_id)
SELECT u.id, g.id
FROM sys_user u
INNER JOIN sys_group g ON g.code = 'MOBILE'
WHERE u.username = 'admin';
