-- شاشات عهدة المندوب على الهاتف
USE namma_erp;

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('m_rep_load', 'هاتف — تحميل عهدة', 'screen', 9060),
('m_rep_return', 'هاتف — إرجاع عهدة', 'screen', 9065),
('m_rep_stock', 'هاتف — رصيد العهدة', 'screen', 9070);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN ('m_rep_load', 'm_rep_return', 'm_rep_stock')
WHERE g.code = 'MOBILE';
