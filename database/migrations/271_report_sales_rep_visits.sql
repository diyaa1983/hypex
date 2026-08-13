-- تقرير زيارات العملاء (دخول/خروج GPS أو يدوي) + شاشة الموبايل
INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('report_sales_rep_visits', 'تقرير زيارات العملاء', 'report', 243),
('m_rep_visit_report', 'هاتف — تقرير الزيارات', 'screen', 9046);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT 1, s.id, 1 FROM sys_screen s WHERE s.code IN ('report_sales_rep_visits', 'm_rep_visit_report');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id AND src.code IN ('sales_rep_route', 'sales_reps', 'report_sales_rep_tours', 'sales_rep_visit_checkout_approve')
INNER JOIN sys_screen s ON s.code = 'report_sales_rep_visits'
WHERE gp.allowed = 1;

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code IN ('MOBILE', 'ADMINS') AND s.code = 'm_rep_visit_report';
