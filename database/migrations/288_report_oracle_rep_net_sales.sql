-- صافي فواتير مبيعات المندوب من Oracle (قراءة فقط)
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_oracle_rep_net_sales', 'صافي فواتير مبيعات المندوب Oracle', 'report', 252
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_oracle_rep_net_sales');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'report_oracle_rep_net_sales'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
