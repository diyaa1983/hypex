-- تقرير المبيعات حسب المنطقة
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_sales_by_region', 'تقرير المبيعات حسب المنطقة', 'report', 203
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_sales_by_region');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'report_sales_by_region'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');

-- نسخ صلاحية من تقرير المندوب إن وُجدت لمجموعات أخرى
INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, gp.allowed
FROM sys_group_permission gp
INNER JOIN sys_screen s_old ON s_old.id = gp.screen_id AND s_old.code = 'report_sales_by_rep'
INNER JOIN sys_screen s_new ON s_new.code = 'report_sales_by_region'
WHERE gp.allowed = 1;
